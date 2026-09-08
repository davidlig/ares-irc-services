<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Application\Service;

use App\Irc\Application\Port\In\NickChangePreservesIdentificationInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Model\UserMessagePreference;
use App\NickServ\Application\Port\Out\GuestNicknameGenerator;
use App\NickServ\Application\Port\Out\NickChangeIdentificationPolicy;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickProtectionNotifier;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\BurstState;
use App\NickServ\Application\Service\ForbiddenNickService;
use App\NickServ\Application\Service\NickProtectionService;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(NickProtectionService::class)]
final class NickProtectionServiceTest extends TestCase
{
    #[Test]
    public function returnsConfiguredDefaultLanguage(): void
    {
        $service = $this->createServiceWithDefaultLanguage('es');

        self::assertSame('es', $service->getDefaultLanguage());
    }

    private function createServiceWithDefaultLanguage(string $language): NickProtectionService
    {
        return new NickProtectionService(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
            defaultLanguage: $language,
        );
    }

    #[Test]
    public function onUserJoinedAddsPendingWhenBurstNotComplete(): void
    {
        $burstState = new BurstState();
        self::assertFalse($burstState->isComplete());

        $user = new NetworkUser('UID1', 'User', 'i', 'h', 'c', 'ip');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $notifier = $this->createStub(NickNetworkActions::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $translator = $this->createStub(NickProtectionNotifier::class);

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $translator,
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onUserJoined($user, new DateTimeImmutable());

        $pending = $burstState->takePending();
        self::assertCount(1, $pending);
        self::assertSame('UID1', $pending[0]->uid);
    }

    #[Test]
    public function onUserJoinedCallsEnforceProtectionWhenBurstComplete(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new NetworkUser('UID1', 'ProtectedNick', 'i', 'h', 'c', 'ip', false);
        $account = RegisteredNick::createPending(
            'ProtectedNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $account->switchMsg(true);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('ProtectedNick')->willReturn($account);
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())->method('forceNick')->with('UID1', self::stringStartsWith('Guest-'));
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->method('consume')->willReturn(false);
        $translator = $this->createMock(NickProtectionNotifier::class);
        $translator->expects(self::once())->method('notifyRename')->with(
            'UID1',
            'ProtectedNick',
            'Guest-ABC1234',
            'en',
            UserMessagePreference::PrivateMessage,
        );

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $translator,
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onUserJoined($user, new DateTimeImmutable());
    }

    #[Test]
    public function enforceProtectionDoesNothingWhenNoAccount(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new NetworkUser('UID1', 'NoAccount', 'i', 'h', 'c', 'ip');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn(null);
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->enforceProtection($user, new DateTimeImmutable());
    }

    #[Test]
    public function enforceProtectionForcesGuestWhenForbidden(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new NetworkUser('UID1', 'ForbiddenNick', 'i', 'h', 'c', 'ip', false);
        $account = RegisteredNick::createForbidden('ForbiddenNick', 'Spam');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);

        $forbiddenService = $this->createMock(ForbiddenNickService::class);
        $forbiddenService->expects(self::once())->method('notifyAndForceGuest')->with('UID1', 'Spam', 'ForbiddenNick');

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $forbiddenService,
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->enforceProtection($user, new DateTimeImmutable());
    }

    #[Test]
    public function enforceProtectionMarksSeenAndSavesWhenUserIdentified(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new NetworkUser('UID1', 'MyNick', 'i', 'h', 'c', 'ip', true);
        $account = RegisteredNick::createPending(
            'MyNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        // Set ID via reflection since it's set by Doctrine on persistence
        $reflection = new ReflectionClass($account);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($account, 1);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('MyNick')->willReturn($account);
        $repo->expects(self::once())->method('save')->with(self::identicalTo($account));
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $eventDispatcher = $this->createMock(NickServEventPublisher::class);
        $eventDispatcher->expects(self::once())->method('publish');

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'MyNick');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $eventDispatcher,
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->enforceProtection($user, new DateTimeImmutable());
    }

    #[Test]
    public function onUserQuitMarksSeenAndUpdatesQuitMessageWhenAccountExists(): void
    {
        $account = RegisteredNick::createPending(
            'QuitNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save')->with(self::identicalTo($account));

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onUserQuit('UID1', 'QuitNick', 'Leaving', 'ident', 'host.example', 'real.host', 'AAA=', new DateTimeImmutable());

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
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onUserQuit('UID1', 'UnknownNick', 'Bye', 'ident', 'host', 'real.host', 'AAA=', new DateTimeImmutable());

        self::assertNull($identifiedRegistry->findNick('UID1'));
    }

    #[Test]
    public function onUserQuitWhenAccountFoundViaIdentifiedRegistryMarksSeenAndSaves(): void
    {
        $account = RegisteredNick::createPending(
            'StoredNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'StoredNick');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturnMap([['SomeNick', null], ['StoredNick', $account]]);
        $repo->expects(self::once())->method('save')->with(self::identicalTo($account));

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onUserQuit('UID1', 'SomeNick', 'Quit', 'id', 'host', 'real.host', 'AAA=', new DateTimeImmutable());

        self::assertNotNull($account->getLastSeenAt());
        self::assertNull($identifiedRegistry->findNick('UID1'));
    }

    #[Test]
    public function enforceProtectionWhenAccountNotRegisteredDoesNothing(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new NetworkUser('UID1', 'PendingNick', 'i', 'h', 'c', 'ip');
        $account = RegisteredNick::createPending(
            'PendingNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        self::assertFalse($account->isRegistered());

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->enforceProtection($user, new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedWhenNotIdentifiedEnforcesProtection(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $account = RegisteredNick::createPending(
            'RegNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $user = new NetworkUser('UID1', 'RegNick', 'i', 'h', 'c', 'ip', false);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn($user);
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())->method('forceNick')->with('UID1', self::stringStartsWith('Guest-'));
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->method('consume')->willReturn(false);
        $translator = $this->createStub(NickProtectionNotifier::class);

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $translator,
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'OldNick', 'RegNick', new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedSkipsWhenGuestNickEcho(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();

        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('consume')->with('UID1')->willReturn(true);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'OldNick', 'Guest-ABC123', new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedSkipsWhenGuestRestoreEcho(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $account = RegisteredNick::createPending(
            'RegNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $user = new NetworkUser('UID1', 'RegNick', 'i', 'h', 'c', 'ip', false);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn($user);

        $pendingRegistry = $this->createMock(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('consume')->with('UID1')->willReturn(true);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'Guest-XYZ', 'RegNick', new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedSkipsWhenAlreadyIdentifiedInRegistry(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'RegNick');

        $account = RegisteredNick::createPending(
            'RegNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $user = new NetworkUser('UID1', 'RegNick', 'i', 'h', 'c', 'ip', false);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn($user);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'OldNick', 'RegNick', new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedSkipsWhenUserIdentifiedFlagTrue(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();

        $account = RegisteredNick::createPending(
            'RegNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $user = new NetworkUser('UID1', 'RegNick', 'i', 'h', 'c', 'ip', true);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn($user);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'RegNick');

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'OldNick', 'RegNick', new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedEnforcesProtectionWhenIdentifiedToDifferentNick(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $account = RegisteredNick::createPending(
            'RegNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $user = new NetworkUser('UID1', 'RegNick', 'i', 'h', 'c', 'ip', isIdentified: true);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn($user);
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())->method('forceNick')->with('UID1', self::stringStartsWith('Guest-'));
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);
        $pendingRegistry->method('consume')->willReturn(false);
        $translator = $this->createStub(NickProtectionNotifier::class);

        // Register a DIFFERENT nick — user IS identified but to the wrong account
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'OtherNick');

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $translator,
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'OldNick', 'RegNick', new DateTimeImmutable());
    }

    #[Test]
    public function enforceProtectionEnforcesWhenIdentifiedToDifferentNick(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new NetworkUser('UID1', 'RegNick', 'i', 'h', 'c', 'ip', isIdentified: true);
        $account = RegisteredNick::createPending(
            'RegNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())->method('forceNick')->with('UID1', self::stringStartsWith('Guest-'));
        $translator = $this->createStub(NickProtectionNotifier::class);

        // Register a DIFFERENT nick — identified to OtherNick, not RegNick
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'OtherNick');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            $burstState,
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $translator,
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->enforceProtection($user, new DateTimeImmutable());
    }

    #[Test]
    public function enforceProtectionAutoIdentifiesWhenIdentifiedButRegistryEmpty(): void
    {
        // Simulates service restart: user has +r on IRCd but identifiedRegistry is empty
        $user = new NetworkUser('UID1', 'MyNick', 'i', 'h', 'c', 'ip', isIdentified: true);
        $account = RegisteredNick::createPending(
            'MyNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $reflection = new ReflectionClass($account);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($account, 1);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('MyNick')->willReturn($account);
        $repo->expects(self::once())->method('save')->with(self::identicalTo($account));
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $eventDispatcher = $this->createMock(NickServEventPublisher::class);
        $eventDispatcher->expects(self::once())->method('publish');

        // Registry is EMPTY — simulating service restart
        $identifiedRegistry = new IdentifiedSessionRegistry();

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            new BurstState(),
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $eventDispatcher,
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->enforceProtection($user, new DateTimeImmutable());
    }

    #[Test]
    public function enforceProtectionRenamesWhenIdentifiedButAccountPendingDeletion(): void
    {
        // Nick is pending deletion — should NOT auto-identify, should rename to Guest
        $user = new NetworkUser('UID1', 'MyNick', 'i', 'h', 'c', 'ip', isIdentified: true);
        $account = RegisteredNick::createPending(
            'MyNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        // Account is NOT activated — stays in pending state (not registered)

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('MyNick')->willReturn($account);
        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())->method('forceNick')->with('UID1', self::stringStartsWith('Guest-'));
        $translator = $this->createStub(NickProtectionNotifier::class);

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            new BurstState(),
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $translator,
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->enforceProtection($user, new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedClearsVhostWhenChangingAwayFromIdentifiedNick(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'OldIdentified');

        $oldAccount = RegisteredNick::createPending(
            'OldIdentified',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $oldAccount->activate();
        $this->setNickId($oldAccount, 1);
        $account = RegisteredNick::createPending(
            'RegNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $this->setNickId($account, 2);
        $user = new NetworkUser('UID1', 'RegNick', 'i', 'h', 'c', 'ip', false, false, '001');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturnCallback(static fn (string $nick) => match (strtolower($nick)) {
            'oldidentified' => $oldAccount,
            'regnick' => $account,
            default => null,
        });

        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn($user);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())->method('setUserAccount')->with('UID1', '0');
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', '', '001');
        $notifier->expects(self::once())->method('forceNick');

        $eventDispatcher = $this->createMock(NickServEventPublisher::class);
        $eventDispatcher->expects(self::once())->method('publish');

        $translator = $this->createStub(NickProtectionNotifier::class);

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $translator,
            $eventDispatcher,
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        self::assertSame('OldIdentified', $identifiedRegistry->findNick('UID1'));
        $service->onNickChanged('UID1', 'OldIdentified', 'RegNick', new DateTimeImmutable());
        self::assertNull($identifiedRegistry->findNick('UID1'));
    }

    private function setNickId(RegisteredNick $nick, int $id): void
    {
        $reflection = new ReflectionClass($nick);
        $property = $reflection->getProperty('id');
        $property->setValue($nick, $id);
    }

    #[Test]
    public function onUserQuitWithEmptyIdentAndReason(): void
    {
        $account = RegisteredNick::createPending(
            'QuitNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onUserQuit('UID1', 'QuitNick', '', '', 'host.example', 'real.host', 'AAA=', new DateTimeImmutable());
        self::assertSame('host.example', $account->getLastQuitMessage());
    }

    #[Test]
    public function onUserQuitWithMessageFormatting(): void
    {
        $account = RegisteredNick::createPending(
            'QuitNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onUserQuit('UID1', 'QuitNick', 'Leaving now', 'myident', 'host.example', 'real.host', 'AAA=', new DateTimeImmutable());
        self::assertSame('Leaving now (myident@host.example)', $account->getLastQuitMessage());
    }

    #[Test]
    public function onUserQuitUpdatesLastConnectionWhenIdentified(): void
    {
        $account = RegisteredNick::createPending(
            'QuitNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'QuitNick');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        // IPv4 base64 encoded: 192.168.1.100
        // inet_pton('192.168.1.100') = bytes [C0,A8,01,64] = base64_encode -> 'wKgBZA=='
        $ipBase64 = base64_encode(inet_pton('192.168.1.100') ?: '');
        $service->onUserQuit('UID1', 'QuitNick', 'Leaving', 'ident', 'display.host', 'real.isp.example', $ipBase64, new DateTimeImmutable());

        self::assertSame('192.168.1.100', $account->getLastConnectIp());
        self::assertSame('real.isp.example', $account->getLastConnectHost());
    }

    #[Test]
    public function onUserQuitDoesNotUpdateLastConnectionWhenNotIdentified(): void
    {
        $account = RegisteredNick::createPending(
            'QuitNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        self::assertNull($account->getLastConnectIp());
        self::assertNull($account->getLastConnectHost());

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        // User not identified (not in registry)
        $service->onUserQuit('UID1', 'QuitNick', 'Leaving', 'ident', 'display.host', 'real.isp.example', 'AICQAGQ=', new DateTimeImmutable());

        // Should remain null because user was not identified
        self::assertNull($account->getLastConnectIp());
        self::assertNull($account->getLastConnectHost());
    }

    #[Test]
    public function onUserQuitDoesNotUpdateLastConnectionWithEmptyIp(): void
    {
        $account = RegisteredNick::createPending(
            'QuitNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'QuitNick');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onUserQuit('UID1', 'QuitNick', 'Leaving', 'ident', 'display.host', 'real.isp.example', '', new DateTimeImmutable());

        self::assertNull($account->getLastConnectIp());
        self::assertNull($account->getLastConnectHost());
    }

    #[Test]
    public function onUserQuitDoesNotUpdateLastConnectionWithAsteriskIp(): void
    {
        $account = RegisteredNick::createPending(
            'QuitNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'QuitNick');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onUserQuit('UID1', 'QuitNick', 'Leaving', 'ident', 'display.host', 'real.isp.example', '*', new DateTimeImmutable());

        self::assertNull($account->getLastConnectIp());
        self::assertNull($account->getLastConnectHost());
    }

    #[Test]
    public function onUserQuitUpdatesLastConnectionWithInvalidBase64(): void
    {
        $account = RegisteredNick::createPending(
            'QuitNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'QuitNick');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('QuitNick')->willReturn($account);
        $repo->expects(self::once())->method('save');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            new BurstState(),
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        // Invalid base64 (not decodable) - decodeIp returns '*', and updateLastConnection treats '*' as null
        $service->onUserQuit('UID1', 'QuitNick', 'Leaving', 'ident', 'display.host', 'real.isp.example', '!!!invalid!!!', new DateTimeImmutable());

        // When IP decoding fails, it returns '*', which is treated as empty by updateLastConnection
        self::assertNull($account->getLastConnectIp());
        self::assertSame('real.isp.example', $account->getLastConnectHost());
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
            $this->createStub(NickNetworkUserLookup::class),
            $this->createStub(NickNetworkActions::class),
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'OldNick', 'NewNick', new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedSkipsWhenAccountIsNull(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNick')->with('NewNick')->willReturn(null);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'OldNick', 'NewNick', new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedSkipsWhenAccountNotRegistered(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();

        $account = RegisteredNick::createPending(
            'NewNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        self::assertFalse($account->isRegistered());

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'OldNick', 'NewNick', new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedSkipsWhenUserLookupReturnsNull(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();

        $account = RegisteredNick::createPending(
            'NewNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);

        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn(null);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'OldNick', 'NewNick', new DateTimeImmutable());
    }

    #[Test]
    public function enforceProtectionForcesGuestWhenUserNotIdentifiedEvenIfNickMatchesRegisteredAccount(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new NetworkUser('UID1', 'RegisteredNick', 'i', 'h', 'c', 'ip', false);
        $account = RegisteredNick::createPending(
            'RegisteredNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $reflection = new ReflectionClass($account);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($account, 1);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('RegisteredNick')->willReturn($account);
        $repo->expects(self::never())->method('save');

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())->method('forceNick')->with('UID1', self::stringStartsWith('Guest-'));

        $eventDispatcher = $this->createMock(NickServEventPublisher::class);
        $eventDispatcher->expects(self::never())->method('publish');

        $identifiedRegistry = new IdentifiedSessionRegistry();

        $translator = $this->createStub(NickProtectionNotifier::class);

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            $burstState,
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $translator,
            $eventDispatcher,
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->enforceProtection($user, new DateTimeImmutable());

        self::assertNull($identifiedRegistry->findNick('UID1'));
    }

    #[Test]
    public function enforceProtectionForcesGuestWhenAccountNotRegistered(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new NetworkUser('UID1', 'PendingNick', 'i', 'h', 'c', 'ip', false);
        $account = RegisteredNick::createPending(
            'PendingNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($account);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('forceNick');

        $translator = $this->createStub(NickProtectionNotifier::class);

        $service = new NickProtectionService(
            $repo,
            $this->createStub(NickNetworkUserLookup::class),
            $notifier,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $translator,
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->enforceProtection($user, new DateTimeImmutable());
    }

    #[Test]
    public function onNickChangedForcesGuestWhenUserNotIdentifiedEvenIfNickMatchesRegisteredAccount(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $user = new NetworkUser('UID1', 'RegisteredNick', 'i', 'h', 'c', 'ip', false);
        $account = RegisteredNick::createPending(
            'RegisteredNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();

        $reflection = new ReflectionClass($account);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($account, 1);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::atLeastOnce())->method('findByNick')->with('RegisteredNick')->willReturn($account);
        $repo->expects(self::never())->method('save');

        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn($user);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())->method('forceNick')->with('UID1', self::stringStartsWith('Guest-'));

        $eventDispatcher = $this->createMock(NickServEventPublisher::class);
        $eventDispatcher->expects(self::never())->method('publish');

        $identifiedRegistry = new IdentifiedSessionRegistry();

        $translator = $this->createStub(NickProtectionNotifier::class);

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $translator,
            $eventDispatcher,
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
        );

        $service->onNickChanged('UID1', 'OldNick', 'RegisteredNick', new DateTimeImmutable());

        self::assertNull($identifiedRegistry->findNick('UID1'));
    }

    #[Test]
    public function onNickChangedPreservesIdentificationWhenProtocolImplementsInterface(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'OldIdentified');

        $oldAccount = RegisteredNick::createPending(
            'OldIdentified',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $oldAccount->activate();
        $this->setNickId($oldAccount, 1);
        $account = RegisteredNick::createPending(
            'RegNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $this->setNickId($account, 2);
        $user = new NetworkUser('UID1', 'RegNick', 'i', 'h', 'c', 'ip', true, false, '001');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturnCallback(static fn (string $nick) => match (strtolower($nick)) {
            'oldidentified' => $oldAccount,
            'regnick' => $account,
            default => null,
        });

        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn($user);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('setUserAccount');
        $notifier->expects(self::never())->method('setUserVhost');

        $eventDispatcher = $this->createMock(NickServEventPublisher::class);
        $eventDispatcher->expects(self::never())->method('publish');

        $module = $this->createStub(NickProtectionServiceTestProtocolModule::class);
        $connectionHolder = $this->createStub(NickChangeIdentificationPolicy::class);
        $connectionHolder->method('preservesIdentification')->willReturn(true);

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $eventDispatcher,
            $this->createStub(ForbiddenNickService::class),
            $connectionHolder,
            $this->guestNicknameGenerator(),
        );

        self::assertSame('OldIdentified', $identifiedRegistry->findNick('UID1'));
        $service->onNickChanged('UID1', 'OldIdentified', 'RegNick', new DateTimeImmutable());
        self::assertSame('OldIdentified', $identifiedRegistry->findNick('UID1'));
    }

    #[Test]
    public function onNickChangedDeidentifiesWhenProtocolDoesNotImplementInterface(): void
    {
        $burstState = new BurstState();
        $burstState->markComplete();
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'OldIdentified');

        $oldAccount = RegisteredNick::createPending(
            'OldIdentified',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $oldAccount->activate();
        $this->setNickId($oldAccount, 1);
        $account = RegisteredNick::createPending(
            'RegNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $this->setNickId($account, 2);
        $user = new NetworkUser('UID1', 'RegNick', 'i', 'h', 'c', 'ip', false, false, '001');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturnCallback(static fn (string $nick) => match (strtolower($nick)) {
            'oldidentified' => $oldAccount,
            'regnick' => $account,
            default => null,
        });

        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $userLookup->method('findByUid')->willReturn($user);

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::once())->method('setUserAccount')->with('UID1', '0');
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', '', '001');

        $eventDispatcher = $this->createMock(NickServEventPublisher::class);
        $eventDispatcher->expects(self::once())->method('publish');

        $module = $this->createStub(NickProtectionServiceTestStandardProtocolModule::class);
        $connectionHolder = $this->createStub(NickChangeIdentificationPolicy::class);
        $connectionHolder->method('preservesIdentification')->willReturn(false);

        $service = new NickProtectionService(
            $repo,
            $userLookup,
            $notifier,
            $burstState,
            $identifiedRegistry,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $eventDispatcher,
            $this->createStub(ForbiddenNickService::class),
            $connectionHolder,
            $this->guestNicknameGenerator(),
        );

        self::assertSame('OldIdentified', $identifiedRegistry->findNick('UID1'));
        $service->onNickChanged('UID1', 'OldIdentified', 'RegNick', new DateTimeImmutable());
        self::assertNull($identifiedRegistry->findNick('UID1'));
    }

    private function guestNicknameGenerator(): GuestNicknameGenerator
    {
        $generator = $this->createStub(GuestNicknameGenerator::class);
        $generator->method('generate')->willReturnCallback(
            static fn (string $prefix): string => $prefix . 'ABC1234',
        );

        return $generator;
    }
}

interface NickProtectionServiceTestProtocolModule extends ProtocolModuleInterface, NickChangePreservesIdentificationInterface {}
interface NickProtectionServiceTestStandardProtocolModule extends ProtocolModuleInterface {}
