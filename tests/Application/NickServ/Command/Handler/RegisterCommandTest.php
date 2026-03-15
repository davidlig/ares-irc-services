<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\Handler\RegisterCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\NickServClientKeyResolver;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\RegisterThrottleRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RegisterCommand::class)]
final class RegisterCommandTest extends TestCase
{
    private function createContext(
        SenderView $sender,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,
            'REGISTER',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
        );
    }

    #[Test]
    public function replyThrottledWhenCooldownRemaining(): void
    {
        $sender = new SenderView('UID1', 'User', 'ident', 'host', 'cloak', 'ip', false, false, '', '');
        $throttle = new RegisterThrottleRegistry();
        $throttle->recordAttempt('ip:ip');

        $clientKeyResolver = new NickServClientKeyResolver();
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $msg) use (&$messages): void {
            $messages[] = $msg;
        });

        $cmd = new RegisterCommand(
            $nickRepo,
            $passwordHasher,
            $throttle,
            $clientKeyResolver,
            $messageBus,
            $translator,
            $logger,
            3600,
        );

        $context = $this->createContext($sender, ['password', 'user@example.com'], $notifier, $translator);
        $cmd->execute($context);

        self::assertNotEmpty($messages);
        self::assertSame('register.throttled', $messages[0]);
    }

    #[Test]
    public function replyInvalidEmailWhenEmailInvalid(): void
    {
        $sender = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $throttle = new RegisterThrottleRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $nickRepo->method('findByNick')->willReturn(null);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RegisterCommand(
            $nickRepo,
            $passwordHasher,
            $throttle,
            $clientKeyResolver,
            $messageBus,
            $translator,
            $logger,
            0,
        );

        $context = $this->createContext($sender, ['pass', 'not-an-email'], $notifier, $translator);
        $cmd->execute($context);

        self::assertSame(['register.invalid_email'], $messages);
    }

    #[Test]
    public function replyEmailAlreadyUsedWhenEmailTaken(): void
    {
        $sender = new SenderView('UID1', 'NewUser', 'i', 'h', 'c', 'ip');
        $existing = $this->createStub(RegisteredNick::class);
        $throttle = new RegisterThrottleRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn($existing);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RegisterCommand(
            $nickRepo,
            $passwordHasher,
            $throttle,
            $clientKeyResolver,
            $messageBus,
            $translator,
            $logger,
            0,
        );

        $context = $this->createContext($sender, ['password', 'taken@example.com'], $notifier, $translator);
        $cmd->execute($context);

        self::assertSame(['register.email_already_used'], $messages);
    }

    #[Test]
    public function replyAlreadyRegisteredWhenNickExists(): void
    {
        $sender = new SenderView('UID1', 'Taken', 'i', 'h', 'c', 'ip');
        $existing = $this->createStub(RegisteredNick::class);
        $existing->method('getStatus')->willReturn(NickStatus::Registered);

        $throttle = new RegisterThrottleRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $nickRepo->method('findByNick')->willReturn($existing);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RegisterCommand(
            $nickRepo,
            $passwordHasher,
            $throttle,
            $clientKeyResolver,
            $messageBus,
            $translator,
            $logger,
            0,
        );

        $context = $this->createContext($sender, ['password', 'user@example.com'], $notifier, $translator);
        $cmd->execute($context);

        self::assertSame(['register.already_registered'], $messages);
    }

    #[Test]
    public function replyAlreadyPendingWhenNickPending(): void
    {
        $sender = new SenderView('UID1', 'Pending', 'i', 'h', 'c', 'ip');
        $existing = $this->createStub(RegisteredNick::class);
        $existing->method('getStatus')->willReturn(NickStatus::Pending);

        $throttle = new RegisterThrottleRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $nickRepo->method('findByNick')->willReturn($existing);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RegisterCommand(
            $nickRepo,
            $passwordHasher,
            $throttle,
            $clientKeyResolver,
            $messageBus,
            $translator,
            $logger,
            0,
        );

        $context = $this->createContext($sender, ['password', 'user@example.com'], $notifier, $translator);
        $cmd->execute($context);

        self::assertSame(['register.already_pending'], $messages);
    }

    #[Test]
    public function replyForbiddenWhenNickForbidden(): void
    {
        $sender = new SenderView('UID1', 'Forbidden', 'i', 'h', 'c', 'ip');
        $existing = $this->createStub(RegisteredNick::class);
        $existing->method('getStatus')->willReturn(NickStatus::Forbidden);

        $throttle = new RegisterThrottleRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $nickRepo->method('findByNick')->willReturn($existing);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RegisterCommand(
            $nickRepo,
            $passwordHasher,
            $throttle,
            $clientKeyResolver,
            $messageBus,
            $translator,
            $logger,
            0,
        );

        $context = $this->createContext($sender, ['password', 'user@example.com'], $notifier, $translator);
        $cmd->execute($context);

        self::assertSame(['register.forbidden'], $messages);
    }

    #[Test]
    public function successPathSavesAccountAndRepliesPending(): void
    {
        $sender = new SenderView('UID1', 'NewNick', 'i', 'h', 'c', 'ip');
        $throttle = new RegisterThrottleRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $nickRepo->method('findByNick')->willReturn(null);
        $nickRepo->expects(self::once())->method('save')->with(self::callback(static fn (RegisteredNick $n): bool => 'NewNick' === $n->getNickname() && $n->isPending()));

        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $passwordHasher->method('hash')->willReturn('hashed');

        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static function (object $m) use (&$dispatched): Envelope {
            $dispatched[] = $m;

            return new Envelope($m);
        });

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RegisterCommand(
            $nickRepo,
            $passwordHasher,
            $throttle,
            $clientKeyResolver,
            $messageBus,
            $translator,
            $logger,
            0,
        );

        $context = $this->createContext($sender, ['secret', 'user@example.com'], $notifier, $translator);
        $cmd->execute($context);

        self::assertSame(['register.pending'], $messages);
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(\App\Application\Mail\Message\SendEmail::class, $dispatched[0]);
    }

    #[Test]
    public function replyMailFailedOnException(): void
    {
        $sender = new SenderView('UID1', 'NewNick', 'i', 'h', 'c', 'ip');
        $throttle = new RegisterThrottleRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn(null);
        $nickRepo->method('findByNick')->willReturn(null);
        $nickRepo->expects(self::once())->method('save');

        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $passwordHasher->method('hash')->willReturn('hashed');

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willThrowException(new RuntimeException('Mail failure'));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RegisterCommand(
            $nickRepo,
            $passwordHasher,
            $throttle,
            $clientKeyResolver,
            $messageBus,
            $translator,
            $logger,
            0,
        );

        $context = $this->createContext($sender, ['secret', 'user@example.com'], $notifier, $translator);
        $cmd->execute($context);

        self::assertSame(['error.mail_failed'], $messages);
    }

    #[Test]
    public function replyEmailAlreadyUsedWhenPendingAccountHasSameEmail(): void
    {
        $sender = new SenderView('UID1', 'NewUser', 'i', 'h', 'c', 'ip');
        $existingPending = $this->createStub(RegisteredNick::class);
        $existingPending->method('getStatus')->willReturn(NickStatus::Pending);
        $throttle = new RegisterThrottleRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByEmail')->willReturn($existingPending);
        $nickRepo->method('findByNick')->willReturn(null);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RegisterCommand(
            $nickRepo,
            $passwordHasher,
            $throttle,
            $clientKeyResolver,
            $messageBus,
            $translator,
            $logger,
            0,
        );

        $context = $this->createContext($sender, ['password', 'pending@example.com'], $notifier, $translator);
        $cmd->execute($context);

        self::assertSame(['register.email_already_used'], $messages);
    }

    #[Test]
    public function doesNothingWhenSenderNull(): void
    {
        $throttle = new RegisterThrottleRegistry();
        $clientKeyResolver = new NickServClientKeyResolver();
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $cmd = new RegisterCommand(
            $nickRepo,
            $passwordHasher,
            $throttle,
            $clientKeyResolver,
            $messageBus,
            $translator,
            $logger,
            0,
        );

        $context = new NickServContext(
            null,
            null,
            'REGISTER',
            ['pass', 'email'],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
        );
        $cmd->execute($context);
    }

    #[Test]
    public function getNameReturnsRegister(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            new RegisterThrottleRegistry(),
            new NickServClientKeyResolver(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame('REGISTER', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            new RegisterThrottleRegistry(),
            new NickServClientKeyResolver(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsTwo(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            new RegisterThrottleRegistry(),
            new NickServClientKeyResolver(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame(2, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsExpectedKey(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            new RegisterThrottleRegistry(),
            new NickServClientKeyResolver(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame('register.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsExpectedKey(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            new RegisterThrottleRegistry(),
            new NickServClientKeyResolver(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame('register.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsOne(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            new RegisterThrottleRegistry(),
            new NickServClientKeyResolver(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame(1, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsExpectedKey(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            new RegisterThrottleRegistry(),
            new NickServClientKeyResolver(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertSame('register.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            new RegisterThrottleRegistry(),
            new NickServClientKeyResolver(),
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
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            new RegisterThrottleRegistry(),
            new NickServClientKeyResolver(),
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
        $cmd = new RegisterCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            new RegisterThrottleRegistry(),
            new NickServClientKeyResolver(),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(LoggerInterface::class),
            0,
        );
        self::assertNull($cmd->getRequiredPermission());
    }
}
