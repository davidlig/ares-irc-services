<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ;

use App\Application\NickServ\BurstState;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\NickServ\NickProtectionService;
use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\NickServ\Service\ForbiddenNickService;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
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
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('ProtectedNick')->willReturn($account);
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
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
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
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->enforceProtection($user);
    }

    #[Test]
    public function enforceProtectionForcesGuestWhenForbidden(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new SenderView('UID1', 'ForbiddenNick', 'i', 'h', 'c', 'ip', false);
        $account = RegisteredNick::createForbidden('ForbiddenNick', 'Spam');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::once())->method('notifyAndForceGuest')->with('UID1', 'Spam', 'ForbiddenNick');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('forceNick');
        $notifier->expects(self::never())->method('sendMessage');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $forbiddenService,
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

        // Set ID via reflection since it's set by Doctrine on persistence
        $reflection = new ReflectionClass($account);
        $idProp = $reflection->getProperty('id');
        $idProp->setAccessible(true);
        $idProp->setValue($account, 1);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('MyNick')->willReturn($account);
        $repo->expects(self::once())->method('save')->with(self::identicalTo($account));
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('forceNick');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            new BurstState(),
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $eventDispatcher,
            $this->createStub(ForbiddenNickService::class),
        );

        $service->enforceProtection($user);
    }

    #[Test]
    public function onUserQuitMarksSeenAndUpdatesQuitMessageWhenAccountExists(): void
    {
        $account = RegisteredNick::createPending('QuitNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save')->with(self::identicalTo($account));

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            new BurstState(),
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onUserQuit('UID1', 'QuitNick', 'Leaving', 'ident', 'host.example');

        self::assertNotNull($account->getLastSeenAt());
        self::assertStringContainsString('Leaving', $account->getLastQuitMessage() ?? '');
    }

    #[Test]
    public function onUserQuitWhenNoAccountDoesNothing(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn(null);
        $identifiedRegistry = new IdentifiedSessionRegistry();

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            new BurstState(),
            $identifiedRegistry,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onUserQuit('UID1', 'UnknownNick', 'Bye', 'ident', 'host');

        self::assertNull($identifiedRegistry->findNick('UID1'));
    }

    #[Test]
    public function onUserQuitWhenAccountFoundViaIdentifiedRegistryMarksSeenAndSaves(): void
    {
        $account = RegisteredNick::createPending('StoredNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'StoredNick');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturnMap([['SomeNick', null], ['StoredNick', $account]]);
        $repo->expects(self::once())->method('save')->with(self::identicalTo($account));

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            new BurstState(),
            $identifiedRegistry,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onUserQuit('UID1', 'SomeNick', 'Quit', 'id', 'host');

        self::assertNotNull($account->getLastSeenAt());
        self::assertNull($identifiedRegistry->findNick('UID1'));
    }

    #[Test]
    public function enforceProtectionWhenAccountNotRegisteredDoesNothing(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new SenderView('UID1', 'PendingNick', 'i', 'h', 'c', 'ip');
        $account = RegisteredNick::createPending('PendingNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        self::assertFalse($account->isRegistered());

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
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
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->enforceProtection($user);
    }

    #[Test]
    public function onNickChangedWhenNotIdentifiedEnforcesProtection(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $account = RegisteredNick::createPending('RegNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();
        $user = new SenderView('UID1', 'RegNick', 'i', 'h', 'c', 'ip', false);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($user);
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
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onNickChanged('UID1', 'OldNick', 'RegNick');
    }

    #[Test]
    public function onNickChangedSkipsWhenGuestNickEcho(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();

        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('consume')->with('UID1')->willReturn(true);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('forceNick');
        $notifier->expects(self::never())->method('sendMessage');

        $service = new NickProtectionService(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NetworkUserLookupPort::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $pendingRegistry,
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onNickChanged('UID1', 'OldNick', 'Guest-ABC123');
    }

    #[Test]
    public function onNickChangedSkipsWhenGuestRestoreEcho(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $account = RegisteredNick::createPending('RegNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();
        $user = new SenderView('UID1', 'RegNick', 'i', 'h', 'c', 'ip', false);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($user);

        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('consume')->with('UID1')->willReturn(true);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('forceNick');
        $notifier->expects(self::never())->method('sendMessage');

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $pendingRegistry,
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onNickChanged('UID1', 'Guest-XYZ', 'RegNick');
    }

    #[Test]
    public function onNickChangedSkipsWhenAlreadyIdentifiedInRegistry(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'RegNick');

        $account = RegisteredNick::createPending('RegNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();
        $user = new SenderView('UID1', 'RegNick', 'i', 'h', 'c', 'ip', false);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($user);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('forceNick');
        $notifier->expects(self::never())->method('sendMessage');

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            $identifiedRegistry,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onNickChanged('UID1', 'OldNick', 'RegNick');
    }

    #[Test]
    public function onNickChangedSkipsWhenUserIdentifiedFlagTrue(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();

        $account = RegisteredNick::createPending('RegNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();
        $user = new SenderView('UID1', 'RegNick', 'i', 'h', 'c', 'ip', true);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($user);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('forceNick');
        $notifier->expects(self::never())->method('sendMessage');

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onNickChanged('UID1', 'OldNick', 'RegNick');
    }

    #[Test]
    public function onNickChangedClearsVhostWhenChangingAwayFromIdentifiedNick(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'OldIdentified');

        $oldAccount = RegisteredNick::createPending('OldIdentified', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $oldAccount->activate();
        $this->setNickId($oldAccount, 1);
        $account = RegisteredNick::createPending('RegNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();
        $this->setNickId($account, 2);
        $user = new SenderView('UID1', 'RegNick', 'i', 'h', 'c', 'ip', false, false, '001');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturnCallback(static fn (string $nick) => match (strtolower($nick)) {
            'oldidentified' => $oldAccount,
            'regnick' => $account,
            default => null,
        });

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($user);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserAccount')->with('UID1', '0');
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', '', '001');
        $notifier->expects(self::exactly(2))->method('sendMessage');
        $notifier->expects(self::once())->method('forceNick');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch');

        $translator = $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            $identifiedRegistry,
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $translator,
            $eventDispatcher,
            $this->createStub(ForbiddenNickService::class),
        );

        self::assertSame('OldIdentified', $identifiedRegistry->findNick('UID1'));
        $service->onNickChanged('UID1', 'OldIdentified', 'RegNick');
        self::assertNull($identifiedRegistry->findNick('UID1'));
    }

    private function setNickId(RegisteredNick $nick, int $id): void
    {
        $reflection = new ReflectionClass($nick);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($nick, $id);
    }

    #[Test]
    public function onUserQuitWithEmptyIdentAndReason(): void
    {
        $account = RegisteredNick::createPending('QuitNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            new BurstState(),
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onUserQuit('UID1', 'QuitNick', '', '', 'host.example');
        self::assertSame('host.example', $account->getLastQuitMessage());
    }

    #[Test]
    public function onUserQuitWithMessageFormatting(): void
    {
        $account = RegisteredNick::createPending('QuitNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            new BurstState(),
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onUserQuit('UID1', 'QuitNick', 'Leaving now', 'myident', 'host.example');
        self::assertSame('Leaving now (myident@host.example)', $account->getLastQuitMessage());
    }

    #[Test]
    public function onNickChangedReturnsEarlyWhenBurstNotComplete(): void
    {
        $burstState = new BurstState();
        self::assertFalse($burstState->isComplete());

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::never())->method('findByNick');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(NickServNotifierInterface::class),
            $burstState,
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onNickChanged('UID1', 'OldNick', 'NewNick');
    }

    #[Test]
    public function onNickChangedSkipsWhenAccountIsNull(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('NewNick')->willReturn(null);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('forceNick');
        $notifier->expects(self::never())->method('sendMessage');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onNickChanged('UID1', 'OldNick', 'NewNick');
    }

    #[Test]
    public function onNickChangedSkipsWhenAccountNotRegistered(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();

        $account = RegisteredNick::createPending('NewNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        self::assertFalse($account->isRegistered());

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('forceNick');
        $notifier->expects(self::never())->method('sendMessage');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NetworkUserLookupPort::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onNickChanged('UID1', 'OldNick', 'NewNick');
    }

    #[Test]
    public function onNickChangedSkipsWhenUserLookupReturnsNull(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();

        $account = RegisteredNick::createPending('NewNick', 'hash', 'u@e.com', 'en', new DateTimeImmutable('+1 hour'));
        $account->activate();

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('forceNick');
        $notifier->expects(self::never())->method('sendMessage');

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(\Symfony\Contracts\Translation\TranslatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(ForbiddenNickService::class),
        );

        $service->onNickChanged('UID1', 'OldNick', 'NewNick');
    }
}
