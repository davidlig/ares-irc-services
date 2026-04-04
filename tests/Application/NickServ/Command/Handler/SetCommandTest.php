<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\Handler\SetCommand;
use App\Application\NickServ\Command\Handler\SetEmailHandler;
use App\Application\NickServ\Command\Handler\SetLanguageHandler;
use App\Application\NickServ\Command\Handler\SetMsgHandler;
use App\Application\NickServ\Command\Handler\SetPasswordHandler;
use App\Application\NickServ\Command\Handler\SetPrivateHandler;
use App\Application\NickServ\Command\Handler\SetTimezoneHandler;
use App\Application\NickServ\Command\Handler\SetVhostHandler;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingEmailChangeRegistry;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\NickProtectabilityResult;
use App\Application\NickServ\Service\NickTargetValidator;
use App\Application\NickServ\VhostDisplayResolver;
use App\Application\NickServ\VhostValidator;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetCommand::class)]
final class SetCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        ?RegisteredNick $senderAccount,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): NickServContext {
        return new NickServContext(
            $sender,
            $senderAccount,
            'SET',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
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

    #[Test]
    public function doesNothingWhenSenderNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslatorInterface::class);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(null, null, ['PASSWORD', 'newpass'], $notifier, $translator));
    }

    #[Test]
    public function replyNotIdentifiedWhenSenderAccountNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), null, ['PASSWORD', 'x'], $notifier, $translator));

        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function replyUnknownOptionWhenOptionNotSupported(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['UNKNOWN', 'value'], $notifier, $translator));

        self::assertSame(['set.unknown_option'], $messages);
    }

    #[Test]
    public function emptyArgsReturnsError(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, [], $notifier, $translator));

        self::assertSame(['set.unknown_option'], $messages);
    }

    #[Test]
    public function delegatesToLanguageHandlerWhenOptionLanguage(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeLanguage')->with('es');
        $account->method('getLanguage')->willReturn('es');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['LANGUAGE', 'es'], $notifier, $translator));

        self::assertSame(['set.language.success'], $messages);
    }

    #[Test]
    public function delegatesToPasswordHandlerWhenOptionPassword(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changePasswordWithHasher')->with('newpass123', self::isInstanceOf(PasswordHasherInterface::class));
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $passwordHasher->method('hash')->willReturn('newhash');
        $setPassword = new SetPasswordHandler($nickRepo, $passwordHasher);
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['PASSWORD', 'newpass123'], $notifier, $translator));

        self::assertSame(['set.password.success'], $messages);
    }

    #[Test]
    public function delegatesToEmailHandlerWhenOptionEmail(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getEmail')->willReturn('old@example.com');
        $account->method('getNickname')->willReturn('User');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturn(new Envelope(new stdClass()));
        $logger = $this->createStub(LoggerInterface::class);
        $translatorForHandler = $this->createStub(TranslatorInterface::class);
        $translatorForHandler->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler(
            $nickRepo,
            new PendingEmailChangeRegistry(),
            $messageBus,
            $translatorForHandler,
            $logger,
        );
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['EMAIL', 'new@example.com'], $notifier, $translator));

        self::assertStringStartsWith('set.email.', $messages[0]);
    }

    #[Test]
    public function delegatesToPrivateHandlerWhenOptionPrivate(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('switchPrivate')->with(true);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['PRIVATE', 'ON'], $notifier, $translator));

        self::assertSame(['set.private.on'], $messages);
    }

    #[Test]
    public function delegatesToMsgHandlerWhenOptionMsg(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('switchMsg')->with(true);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['MSG', 'ON'], $notifier, $translator));

        self::assertSame(['set.msg.on'], $messages);
    }

    #[Test]
    public function delegatesToTimezoneHandlerWhenOptionTimezone(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeTimezone')->with('America/New_York');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['TIMEZONE', 'America/New_York'], $notifier, $translator));

        self::assertSame(['set.timezone.success'], $messages);
    }

    #[Test]
    public function delegatesToVhostHandlerWhenOptionVhost(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeVhost')->with('vhost.example.com');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['VHOST', 'vhost.example.com'], $notifier, $translator));

        self::assertStringStartsWith('set.vhost.', $messages[0]);
    }

    #[Test]
    public function handlesLowercaseOption(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeLanguage')->with('es');
        $account->method('getLanguage')->willReturn('es');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['language', 'es'], $notifier, $translator));

        self::assertSame(['set.language.success'], $messages);
    }

    #[Test]
    public function passwordHandlerReturnsSyntaxErrorOnEmptyValue(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['PASSWORD', ''], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function emailHandlerReturnsSyntaxErrorOnEmptyValue(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['EMAIL', ''], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function emailHandlerReturnsInvalidEmailError(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getEmail')->willReturn('old@example.com');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['EMAIL', 'not-an-email'], $notifier, $translator));

        self::assertSame(['register.invalid_email'], $messages);
    }

    #[Test]
    public function emailHandlerReturnsEmailAlreadyUsedError(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getEmail')->willReturn('old@example.com');
        $account->method('getNickname')->willReturn('User');
        $existingAccount = $this->createStub(RegisteredNick::class);
        $existingAccount->method('getNickname')->willReturn('OtherUser');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn($existingAccount);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['EMAIL', 'used@example.com'], $notifier, $translator));

        self::assertSame(['register.email_already_used'], $messages);
    }

    #[Test]
    public function languageHandlerReturnsSyntaxErrorOnEmptyValue(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['LANGUAGE', ''], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function languageHandlerReturnsInvalidLanguageError(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('en');
        $account->method('changeLanguage')->willThrowException(new InvalidArgumentException('Unsupported language'));
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['LANGUAGE', 'invalid-lang'], $notifier, $translator));

        self::assertSame(['set.language.invalid'], $messages);
    }

    #[Test]
    public function privateHandlerReturnsSyntaxErrorOnInvalidValue(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['PRIVATE', 'MAYBE'], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function msgHandlerReturnsSyntaxErrorOnInvalidValue(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['MSG', 'YES'], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function timezoneHandlerReturnsSyntaxErrorOnEmptyValue(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['TIMEZONE', ''], $notifier, $translator));

        self::assertSame(['error.syntax'], $messages);
    }

    #[Test]
    public function timezoneHandlerReturnsInvalidTimezoneError(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getTimezone')->willReturn('UTC');
        $account->method('changeTimezone')->willThrowException(new InvalidArgumentException('Invalid timezone'));
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['TIMEZONE', 'Not/A/Timezone'], $notifier, $translator));

        self::assertSame(['set.timezone.invalid'], $messages);
    }

    #[Test]
    public function vhostHandlerReturnsInvalidErrorOnBadFormat(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['VHOST', '**invalid**'], $notifier, $translator));

        self::assertSame(['set.vhost.invalid'], $messages);
    }

    #[Test]
    public function vhostHandlerReturnsTakenErrorWhenVhostInUse(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $existingAccount = $this->createStub(RegisteredNick::class);
        $existingAccount->method('getId')->willReturn(2);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByVhost')->willReturn($existingAccount);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['VHOST', 'taken.vhost'], $notifier, $translator));

        self::assertSame(['set.vhost.taken'], $messages);
    }

    #[Test]
    public function privateHandlerTurnsOffPrivate(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('switchPrivate')->with(false);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['PRIVATE', 'OFF'], $notifier, $translator));

        self::assertSame(['set.private.off'], $messages);
    }

    #[Test]
    public function msgHandlerTurnsOffMsg(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('switchMsg')->with(false);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['MSG', 'OFF'], $notifier, $translator));

        self::assertSame(['set.msg.off'], $messages);
    }

    #[Test]
    public function timezoneHandlerClearsTimezoneWithOff(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeTimezone')->with(null);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['TIMEZONE', 'OFF'], $notifier, $translator));

        self::assertSame(['set.timezone.cleared'], $messages);
    }

    #[Test]
    public function vhostHandlerClearsVhost(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeVhost')->with(null);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['VHOST', 'OFF'], $notifier, $translator));

        self::assertSame(['set.vhost.cleared'], $messages);
    }

    #[Test]
    public function getNameReturnsSet(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        self::assertSame('SET', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsSetSyntax(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        self::assertSame('set.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsSetHelp(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        self::assertSame('set.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsFour(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        self::assertSame(4, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsSetShort(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        self::assertSame('set.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsExpectedOptions(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $subCommands = $cmd->getSubCommandHelp();

        self::assertCount(7, $subCommands);
        self::assertSame('PASSWORD', $subCommands[0]['name']);
        self::assertSame('EMAIL', $subCommands[1]['name']);
        self::assertSame('LANGUAGE', $subCommands[2]['name']);
        self::assertSame('TIMEZONE', $subCommands[3]['name']);
        self::assertSame('PRIVATE', $subCommands[4]['name']);
        self::assertSame('MSG', $subCommands[5]['name']);
        self::assertSame('VHOST', $subCommands[6]['name']);
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsSetPermission(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        self::assertSame(NickServPermission::SET, $cmd->getRequiredPermission());
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        self::assertSame([], $cmd->getHelpParams());
    }

    #[Test]
    public function executeIrcopModeAllowsIrcopToModifyRegularUser(): void
    {
        $targetAccount = $this->createMock(RegisteredNick::class);
        $targetAccount->expects(self::once())->method('changeLanguage')->with('es');
        $targetAccount->method('getLanguage')->willReturn('es');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($targetAccount);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TargetUser', $targetAccount));

        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $validator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['TargetUser', 'LANGUAGE', 'es'], $notifier, $translator));

        self::assertSame(['set.language.success'], $messages);
    }

    #[Test]
    public function executeIrcopModeRejectsRootTarget(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::root('RootUser'));

        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ($params ? json_encode($params) : ''));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $validator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['RootUser', 'PASSWORD', 'newpass'], $notifier, $translator));

        self::assertStringContainsString('set.cannot_modify_oper', $messages[0]);
    }

    #[Test]
    public function executeIrcopModeRejectsIrcopTarget(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::ircop('OperUser'));

        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ($params ? json_encode($params) : ''));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $validator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['OperUser', 'PASSWORD', 'newpass'], $notifier, $translator));

        self::assertStringContainsString('set.cannot_modify_oper', $messages[0]);
    }

    #[Test]
    public function executeIrcopModeRejectsServiceNick(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::service('NickServ'));

        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ($params ? json_encode($params) : ''));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $validator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['NickServ', 'PASSWORD', 'newpass'], $notifier, $translator));

        self::assertStringContainsString('set.cannot_modify_oper', $messages[0]);
    }

    #[Test]
    public function executeIrcopModeRejectsUnregisteredNick(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('UnregisteredUser', null));

        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . ($params ? json_encode($params) : ''));

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $validator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['UnregisteredUser', 'PASSWORD', 'newpass'], $notifier, $translator));

        self::assertStringContainsString('set.not_registered_ircop', $messages[0]);
    }

    #[Test]
    public function getAuditDataReturnsNullAfterOwnerMode(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->expects(self::once())->method('changeLanguage')->with('es');
        $account->method('getLanguage')->willReturn('es');
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $this->createStub(NickTargetValidator::class));
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), $account, ['LANGUAGE', 'es'], $notifier, $translator));

        self::assertNull($cmd->getAuditData($this->createStub(NickServContext::class)));
    }

    #[Test]
    public function getAuditDataReturnsIrcopAuditDataAfterIrcopMode(): void
    {
        $targetAccount = $this->createMock(RegisteredNick::class);
        $targetAccount->expects(self::once())->method('changeLanguage')->with('es');
        $targetAccount->method('getLanguage')->willReturn('es');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($targetAccount);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TargetUser', $targetAccount));

        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $validator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['TargetUser', 'LANGUAGE', 'es'], $notifier, $translator));

        $auditData = $cmd->getAuditData($this->createStub(NickServContext::class));
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TargetUser', $auditData->target);
        self::assertSame('LANGUAGE', $auditData->extra['option']);
        self::assertSame('es', $auditData->extra['value']);
    }

    #[Test]
    public function getAuditDataExcludesPasswordValueInIrcopMode(): void
    {
        $targetAccount = $this->createMock(RegisteredNick::class);
        $targetAccount->expects(self::once())->method('changePasswordWithHasher');

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('save')->with($targetAccount);

        $validator = $this->createStub(NickTargetValidator::class);
        $validator->method('validate')->willReturn(NickProtectabilityResult::allowed('TargetUser', $targetAccount));

        $setPassword = new SetPasswordHandler($nickRepo, $this->createStub(PasswordHasherInterface::class));
        $setEmail = new SetEmailHandler($nickRepo, new PendingEmailChangeRegistry(), $this->createStub(MessageBusInterface::class), $this->createStub(TranslatorInterface::class), $this->createStub(LoggerInterface::class));
        $setLanguage = new SetLanguageHandler($nickRepo);
        $setPrivate = new SetPrivateHandler($nickRepo);
        $setMsg = new SetMsgHandler($nickRepo);
        $setTimezone = new SetTimezoneHandler($nickRepo);
        $setVhost = new SetVhostHandler($nickRepo, new VhostValidator(), new VhostDisplayResolver(''), $this->createStub(NetworkUserLookupPort::class));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new SetCommand($setPassword, $setEmail, $setLanguage, $setPrivate, $setMsg, $setTimezone, $setVhost, $nickRepo, $validator);
        $cmd->execute($this->createContext(new SenderView('UID1', 'OperUser', 'i', 'h', 'c', 'ip'), $this->createStub(RegisteredNick::class), ['TargetUser', 'PASSWORD', 'secret123'], $notifier, $translator));

        $auditData = $cmd->getAuditData($this->createStub(NickServContext::class));
        self::assertInstanceOf(IrcopAuditData::class, $auditData);
        self::assertSame('TargetUser', $auditData->target);
        self::assertSame('PASSWORD', $auditData->extra['option']);
        self::assertNull($auditData->extra['value']);
    }
}
