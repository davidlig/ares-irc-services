<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\Handler\RecoverCommand;
use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\Service\PasswordHasherInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
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
            new \App\Application\NickServ\PendingVerificationRegistry(),
            $recoveryRegistry,
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

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, 3600, 0);
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

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator, new RecoveryTokenRegistry()));

        self::assertSame(['recover.pending'], $messages);
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

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['Nick'], $notifier, $translator, new RecoveryTokenRegistry()));

        self::assertSame(['recover.no_email'], $messages);
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

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, 3600, 0);
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

        $cmd = new RecoverCommand($nickRepo, $messageBus, $translator, $passwordHasher, $logger, 3600, 0);
        $cmd->execute($this->createContext(new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip'), ['User', 'valid-token'], $notifier, $translator, $recovery));

        self::assertSame(['recover.success_identify', 'recover.success_then_change'], $messages);
    }
}
