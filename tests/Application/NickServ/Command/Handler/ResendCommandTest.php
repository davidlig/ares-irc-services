<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\Handler\ResendCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(ResendCommand::class)]
final class ResendCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
        PendingVerificationRegistry $pendingRegistry,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,
            'RESEND',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            $pendingRegistry,
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function doesNothingWhenSenderNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $cmd = new ResendCommand($nickRepo, $messageBus, $translator, $logger, 0);
        $cmd->execute($this->createContext(null, [], $notifier, $translator, new PendingVerificationRegistry()));
    }

    #[Test]
    public function replyNoPendingWhenAccountNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new ResendCommand($nickRepo, $messageBus, $translator, $logger, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), [], $notifier, $translator, new PendingVerificationRegistry()));

        self::assertSame(['resend.no_pending'], $messages);
    }

    #[Test]
    public function replyNoPendingWhenAccountNotPending(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new ResendCommand($nickRepo, $messageBus, $translator, $logger, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), [], $notifier, $translator, new PendingVerificationRegistry()));

        self::assertSame(['resend.no_pending'], $messages);
    }

    #[Test]
    public function replyThrottledWhenResendCooldownActive(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getEmail')->willReturn('user@example.com');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);
        $pending = new PendingVerificationRegistry();
        $pending->recordResend('user');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new ResendCommand($nickRepo, $messageBus, $translator, $logger, 3600);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), [], $notifier, $translator, $pending));

        self::assertSame(['resend.throttled'], $messages);
    }

    #[Test]
    public function successStoresTokenAndReplies(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getEmail')->willReturn('user@example.com');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static function (object $m) use (&$dispatched): Envelope {
            $dispatched[] = $m;

            return new Envelope($m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);
        $pending = new PendingVerificationRegistry();

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new ResendCommand($nickRepo, $messageBus, $translator, $logger, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), [], $notifier, $translator, $pending));

        self::assertSame(['resend.success'], $messages);
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(\App\Application\Mail\Message\SendEmail::class, $dispatched[0]);
    }

    #[Test]
    public function replyErrorWhenAccountNullEmail(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getEmail')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);
        $pending = new PendingVerificationRegistry();

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new ResendCommand($nickRepo, $messageBus, $translator, $logger, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), [], $notifier, $translator, $pending));

        self::assertSame(['resend.success'], $messages);
    }

    #[Test]
    public function resendWhenIntervalZero(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getEmail')->willReturn('user@example.com');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static function (object $m) use (&$dispatched): Envelope {
            $dispatched[] = $m;

            return new Envelope($m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);
        $pending = new PendingVerificationRegistry();
        $pending->recordResend('User');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new ResendCommand($nickRepo, $messageBus, $translator, $logger, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), [], $notifier, $translator, $pending));

        self::assertSame(['resend.success'], $messages);
        self::assertCount(1, $dispatched);
    }

    #[Test]
    public function mailDispatchFailureLogsAndRepliesError(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getEmail')->willReturn('user@example.com');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willThrowException(new RuntimeException('SMTP failure'));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'NickServ RESEND: failed to dispatch verification email',
            self::callback(static fn (array $ctx) => isset($ctx['nick'], $ctx['recipient'], $ctx['exception']))
        );
        $pending = new PendingVerificationRegistry();

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new ResendCommand($nickRepo, $messageBus, $translator, $logger, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), [], $notifier, $translator, $pending));

        self::assertSame(['error.mail_failed'], $messages);
    }

    #[Test]
    public function noThrottleWhenNoPreviousResendAndIntervalPositive(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $account->method('getEmail')->willReturn('user@example.com');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static function (object $m) use (&$dispatched): Envelope {
            $dispatched[] = $m;

            return new Envelope($m);
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);
        $pending = new PendingVerificationRegistry();

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new ResendCommand($nickRepo, $messageBus, $translator, $logger, 3600);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), [], $notifier, $translator, $pending));

        self::assertSame(['resend.success'], $messages);
        self::assertCount(1, $dispatched);
    }

    #[Test]
    public function getNameReturnsResend(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame('RESEND', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsZero(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame(0, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsExpectedKey(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame('resend.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsExpectedKey(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame('resend.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsFour(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame(4, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsExpectedKey(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame('resend.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertNull($cmd->getRequiredPermission());
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = new ResendCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );

        self::assertSame([], $cmd->getHelpParams());
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick)
            {
            }

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }
}
