<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\NickServ\Command\Handler\RecoverCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickPasswordChangedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(RecoverCommand::class)]
final class RecoverCommandTest extends TestCase
{
    private function createContext(
        ?SenderView $sender,
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
        RecoveryTokenRegistry $recoveryRegistry,
    ): NickServContext {
        return new NickServContext(
            $sender,
            null,
            'RECOVER',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            $recoveryRegistry,
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function requestTokenReplyNotRegisteredWhenAccountNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['SomeNick'], $notifier, $translator, new RecoveryTokenRegistry()));

        self::assertSame(['recover.not_registered'], $messages);
    }

    #[Test]
    public function requestTokenReplyPendingWhenAccountPending(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator, new RecoveryTokenRegistry()));

        self::assertSame(['recover.pending'], $messages);
    }

    #[Test]
    public function requestTokenReplySuspendedWhenAccountSuspended(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(true);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getReason')->willReturn('Violation of rules');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator, new RecoveryTokenRegistry()));

        self::assertSame(['recover.suspended'], $messages);
    }

    #[Test]
    public function requestTokenReplyForbiddenWhenAccountForbidden(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(true);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator, new RecoveryTokenRegistry()));

        self::assertSame(['recover.forbidden'], $messages);
    }

    #[Test]
    public function requestTokenReplyNoEmailWhenEmailNull(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getEmail')->willReturn(null);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator, new RecoveryTokenRegistry()));

        self::assertSame(['recover.no_email'], $messages);
    }

    #[Test]
    public function requestTokenReplyNoEmailWhenEmailEmptyString(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getEmail')->willReturn('');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator, new RecoveryTokenRegistry()));

        self::assertSame(['recover.no_email'], $messages);
    }

    #[Test]
    public function requestTokenReplyThrottledWhenRecentlyRequested(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getEmail')->willReturn('user@example.com');
        $account->method('getLanguage')->willReturn('en');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $recovery = new RecoveryTokenRegistry();
        $recovery->recordRecover('Nick');

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 300);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator, $recovery));

        self::assertSame(['recover.throttled'], $messages);
    }

    #[Test]
    public function requestTokenSuccessSendsEmail(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getEmail')->willReturn('user@example.com');
        $account->method('getLanguage')->willReturn('en');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $translatorCalls = [];
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $params = [], string $domain = 'nickserv', string $locale = 'en') use (&$translatorCalls): string {
            $translatorCalls[] = ['id' => $id, 'params' => $params, 'domain' => $domain, 'locale' => $locale];

            return $id;
        });
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $recovery = new RecoveryTokenRegistry();

        $dispatched = [];
        $messageBus = $this->createStub(MessageBusInterface::class);
        $messageBus->method('dispatch')->willReturnCallback(static function (object $m) use (&$dispatched): Envelope {
            $dispatched[] = $m;

            return new Envelope($m);
        });

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('getNick')->willReturn('NickServ');

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator, $recovery));

        self::assertSame(['recover.email_sent'], $messages);
        self::assertCount(1, $dispatched);
        self::assertInstanceOf(\App\Application\Mail\Message\SendEmail::class, $dispatched[0]);

        $emailSubjectCalls = array_filter($translatorCalls, static fn (array $c): bool => 'recovery_token_subject' === $c['id']);
        self::assertCount(1, $emailSubjectCalls, 'Subject translation should be called once');
        $subjectCall = reset($emailSubjectCalls);
        self::assertSame('recovery_token_subject', $subjectCall['id']);
        self::assertSame('mail', $subjectCall['domain']);
        self::assertArrayHasKey('%bot%', $subjectCall['params']);
        self::assertSame('NickServ', $subjectCall['params']['%bot%']);
    }

    #[Test]
    public function requestTokenReplyMailFailedOnException(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getEmail')->willReturn('user@example.com');
        $account->method('getLanguage')->willReturn('en');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $recovery = new RecoveryTokenRegistry();

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects(self::once())->method('dispatch')->willThrowException(new RuntimeException('Mail failure'));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator, $recovery));

        self::assertSame(['error.mail_failed'], $messages);
    }

    #[Test]
    public function consumeTokenReplyNotRegisteredWhenAccountNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $recovery = new RecoveryTokenRegistry();

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick', 'some-token'], $notifier, $translator, $recovery));

        self::assertSame(['recover.not_registered'], $messages);
    }

    #[Test]
    public function consumeTokenReplyPendingWhenAccountPending(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(true);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $recovery = new RecoveryTokenRegistry();

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick', 'token'], $notifier, $translator, $recovery));

        self::assertSame(['recover.pending'], $messages);
    }

    #[Test]
    public function consumeTokenReplySuspendedWhenAccountSuspended(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(true);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getReason')->willReturn('Abuse');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $recovery = new RecoveryTokenRegistry();

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick', 'token'], $notifier, $translator, $recovery));

        self::assertSame(['recover.suspended'], $messages);
    }

    #[Test]
    public function consumeTokenReplyForbiddenWhenAccountForbidden(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(true);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $recovery = new RecoveryTokenRegistry();

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick', 'token'], $notifier, $translator, $recovery));

        self::assertSame(['recover.forbidden'], $messages);
    }

    #[Test]
    public function consumeTokenReplyInvalidTokenWhenTokenWrong(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $recovery = new RecoveryTokenRegistry();

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick', 'wrong-token'], $notifier, $translator, $recovery));

        self::assertSame(['recover.invalid_token'], $messages);
    }

    #[Test]
    public function consumeTokenSuccessResetsPasswordAndReplies(): void
    {
        $account = $this->createMock(RegisteredNick::class);
        $account->method('isPending')->willReturn(false);
        $account->method('isSuspended')->willReturn(false);
        $account->method('isForbidden')->willReturn(false);
        $account->method('getNickname')->willReturn('User');
        $account->expects(self::once())->method('changePasswordWithHasher')->with(self::anything(), self::anything());
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $nickRepo->expects(self::once())->method('save')->with($account);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);
        $recovery = new RecoveryTokenRegistry();
        $expires = (new DateTimeImmutable())->modify('+1 hour');
        $recovery->store('User', 'valid-token', $expires);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'valid-token'], $notifier, $translator, $recovery));

        self::assertSame(['recover.success_identify', 'recover.success_then_change'], $messages);
    }

    #[Test]
    public function recoverWithEmptyIpDispatchesEventWithAsteriskIp(): void
    {
        $nick = $this->createNickWithId('User', 1);
        $nick->activate();

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);
        $nickRepo->expects(self::once())->method('save');

        $passwordHasher = $this->createMock(PasswordHasherInterface::class);
        $passwordHasher->expects(self::once())->method('hash');

        $recovery = new RecoveryTokenRegistry();
        $expires = (new DateTimeImmutable())->modify('+1 hour');
        $recovery->store('User', 'token123', $expires);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RecoverCommand(
            $nickRepo,
            $this->createStub(MessageBusInterface::class),
            $translator,
            $passwordHasher,
            $this->createStub(LoggerInterface::class),
            $eventDispatcher,
            3600,
            0,
        );

        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', ''), ['User', 'token123'], $notifier, $translator, $recovery));

        self::assertContains('recover.success_identify', $messages);
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(NickPasswordChangedEvent::class, $dispatchedEvents[0]);
        self::assertSame('*', $dispatchedEvents[0]->performedByIp);
    }

    #[Test]
    public function recoverWithInvalidBase64IpDispatchesEventWithOriginalIp(): void
    {
        $nick = $this->createNickWithId('User', 1);
        $nick->activate();

        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($nick);
        $nickRepo->expects(self::once())->method('save');

        $passwordHasher = $this->createMock(PasswordHasherInterface::class);
        $passwordHasher->expects(self::once())->method('hash');

        $recovery = new RecoveryTokenRegistry();
        $expires = (new DateTimeImmutable())->modify('+1 hour');
        $recovery->store('User', 'token123', $expires);

        $dispatchedEvents = [];
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->willReturnCallback(static function (object $event) use (&$dispatchedEvents): object {
                $dispatchedEvents[] = $event;

                return $event;
            });

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new RecoverCommand(
            $nickRepo,
            $this->createStub(MessageBusInterface::class),
            $translator,
            $passwordHasher,
            $this->createStub(LoggerInterface::class),
            $eventDispatcher,
            3600,
            0,
        );

        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'invalid!base64'), ['User', 'token123'], $notifier, $translator, $recovery));

        self::assertContains('recover.success_identify', $messages);
        self::assertCount(1, $dispatchedEvents);
        self::assertInstanceOf(NickPasswordChangedEvent::class, $dispatchedEvents[0]);
        self::assertSame('invalid!base64', $dispatchedEvents[0]->performedByIp);
    }

    #[Test]
    public function doesNothingWhenSenderNull(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $messageBus = $this->createStub(MessageBusInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $passwordHasher = $this->createStub(PasswordHasherInterface::class);
        $logger = $this->createStub(LoggerInterface::class);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, $this->createStub(EventDispatcherInterface::class), 3600, 0);
        $cmd->execute($this->createContext(null, ['Nick'], $notifier, $translator, new RecoveryTokenRegistry()));
    }

    #[Test]
    public function getNameReturnsRecover(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
            0,
        );
        self::assertSame('RECOVER', $cmd->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
            0,
        );
        self::assertSame([], $cmd->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
            0,
        );
        self::assertSame(1, $cmd->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsExpectedKey(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
            0,
        );
        self::assertSame('recover.syntax', $cmd->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsExpectedKey(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
            0,
        );
        self::assertSame('recover.help', $cmd->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsFive(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
            0,
        );
        self::assertSame(5, $cmd->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsExpectedKey(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
            0,
        );
        self::assertSame('recover.short', $cmd->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
            0,
        );
        self::assertSame([], $cmd->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
            0,
        );
        self::assertFalse($cmd->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
            0,
        );
        self::assertNull($cmd->getRequiredPermission());
    }

    #[Test]
    public function getHelpParamsReturnsEmptyArray(): void
    {
        $cmd = new RecoverCommand(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(MessageBusInterface::class),
            $this->createStub(TranslatorInterface::class),
            $this->createStub(PasswordHasherInterface::class),
            $this->createStub(LoggerInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            3600,
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

    private function createNickWithId(string $nickname, int $id): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $reflection = new ReflectionClass(RegisteredNick::class);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($nick, $id);

        return $nick;
    }
}
