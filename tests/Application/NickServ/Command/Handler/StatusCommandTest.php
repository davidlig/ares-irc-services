<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\Command\Handler\StatusCommand;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(StatusCommand::class)]
final class StatusCommandTest extends TestCase
{
    private function createContext(
        array $args,
        NickServNotifierInterface $notifier,
        TranslatorInterface $translator,
    ): NickServContext {
        return new NickServContext(
            new SenderView('UID1', 'Caller', 'i', 'h', 'c', 'ip'),
            null,
            'STATUS',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new \App\Application\NickServ\PendingVerificationRegistry(),
            new \App\Application\NickServ\RecoveryTokenRegistry(),
        );
    }

    #[Test]
    public function notRegisteredOfflineWhenNoAccountAndUserNotOnline(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(null);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $p = []): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['SomeNick'], $notifier, $translator));

        self::assertContains('status.header', $messages);
        self::assertContains('status.not_registered_offline', $messages);
        self::assertContains('status.footer', $messages);
    }

    #[Test]
    public function notRegisteredOnlineWhenNoAccountAndUserOnline(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn(null);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('U2', 'SomeNick', 'i', 'h', 'c', 'ip'));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['SomeNick'], $notifier, $translator));

        self::assertContains('status.unregistered', $messages);
    }

    #[Test]
    public function pendingShowsPendingAndExpiry(): void
    {
        $expires = (new DateTimeImmutable())->modify('+30 minutes');
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Pending);
        $account->method('getExpiresAt')->willReturn($expires);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.pending', $messages);
    }

    #[Test]
    public function registeredIdentifiedWhenOnlineAndIdentified(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn(new SenderView('U2', 'Nick', 'i', 'h', 'c', 'ip', true, false, '', ''));

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.identified', $messages);
    }

    #[Test]
    public function suspendedShowsReason(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Suspended);
        $account->method('getReason')->willReturn('Abuse');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $cmd = new StatusCommand($nickRepo, $userLookup);
        $cmd->execute($this->createContext(['Nick'], $notifier, $translator));

        self::assertContains('status.suspended', $messages);
        self::assertContains('status.suspended_reason', $messages);
    }
}
