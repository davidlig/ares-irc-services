<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\BurstState;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\NickProtectionService;
use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickProtectionService::class)]
final class NickProtectionServiceTest extends TestCase
{
    #[Test]
    public function onUserJoinedAddsPendingWhenBurstNotComplete(): void
    {
        $burstState = new BurstState();
        self::assertFalse($burstState->isComplete());

        $user = new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $pendingRegistry,
            $translator,
        );

        $service->onUserJoined($user);

        $pending = $burstState->takePending();
        self::assertCount(1, $pending);
        self::assertSame('UID1', $pending[0]->uid);
    }

    #[Test]
    public function onUserJoinedCallsEnforceProtectionWhenBurstComplete(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new SenderView('UID1', 'ProtectedNick', 'i', 'h', 'c', 'ip', false);
        $account = RegisteredNick::createPending('ProtectedNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->with('ProtectedNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::exactly(2))->method('sendMessage');
        $notifier->expects(self::once())->method('forceNick')->with('UID1', self::stringStartsWith('Guest-'));
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->method('consume')->willReturn(false);
        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $pendingRegistry,
            $translator,
        );

        $service->onUserJoined($user);
    }

    #[Test]
    public function enforceProtectionDoesNothingWhenNoAccount(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new SenderView('UID1', 'NoAccount', 'i', 'h', 'c', 'ip');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn(null);
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
        );

        $service->enforceProtection($user);
    }

    #[Test]
    public function enforceProtectionMarksSeenAndSavesWhenUserIdentified(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new SenderView('UID1', 'MyNick', 'i', 'h', 'c', 'ip', true);
        $account = RegisteredNick::createPending('MyNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->with('MyNick')->willReturn($account);
        $repo->expects(self::once())->method('save')->with(self::identicalTo($account));
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
        );

        $service->enforceProtection($user);
    }

    #[Test]
    public function onUserQuitMarksSeenAndUpdatesQuitMessageWhenAccountExists(): void
    {
        $account = RegisteredNick::createPending('QuitNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save')->with(self::identicalTo($account));

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            new BurstState(),
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
        );

        $service->onUserQuit('UID1', 'QuitNick', 'Leaving', 'ident', 'host.example');

        self::assertNotNull($account->getLastSeenAt());
        self::assertStringContainsString('Leaving', $account->getLastQuitMessage() ?? '');
    }
}
