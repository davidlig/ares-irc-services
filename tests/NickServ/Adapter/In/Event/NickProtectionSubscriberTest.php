<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\NativeNicknameAuthenticationInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\NickChangePreservesIdentificationInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\PublishedEvent\IrcMessageHandledEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkAppEvent;
use App\Irc\Application\PublishedEvent\UserJoinedNetworkDTO;
use App\Irc\Application\PublishedEvent\UserLeftNetworkEvent;
use App\Irc\Application\PublishedEvent\UserModesChangedEvent;
use App\Irc\Application\PublishedEvent\UserNicknameChangedEvent;
use App\MemoServ\Adapter\In\Event\NickBurstMemoCacheSubscriber;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\BurstMemoNoticeCache;
use App\NickServ\Adapter\In\Event\NickProtectionSubscriber;
use App\NickServ\Adapter\Out\Event\SymfonyNickServEventPublisher;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\SessionLanguageRegistry;
use App\NickServ\Application\Model\NetworkUser;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;
use App\NickServ\Application\Port\Out\GuestNicknameGenerator;
use App\NickServ\Application\Port\Out\NickChangeIdentificationPolicy;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\Port\Out\NickNetworkActions;
use App\NickServ\Application\Port\Out\NickNetworkUserLookup;
use App\NickServ\Application\Port\Out\NickProtectionNotifier;
use App\NickServ\Application\Port\Out\NickServEventPublisher;
use App\NickServ\Application\Port\Out\PendingNickProtectionRegistryInterface;
use App\NickServ\Application\Port\Out\PendingNickRestoreRegistryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickBurstBatchFinishedEvent;
use App\NickServ\Application\PublishedEvent\NickBurstBatchStartedEvent;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use App\NickServ\Application\Service\BurstState;
use App\NickServ\Application\Service\ForbiddenNickService;
use App\NickServ\Application\Service\IdentifiedUserVhostSyncService;
use App\NickServ\Application\Service\NickProtectionService;
use App\NickServ\Application\Service\PendingNickActivities;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\UseCase\IssueMigrationRescueToken\IssueMigrationRescueTokenHandlerInterface;
use App\NickServ\Application\UseCase\SyncNetworkIdentification\SyncNetworkIdentification;
use App\NickServ\Application\UseCase\SyncNetworkIdentification\SyncNetworkIdentificationHandlerInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\OperServ\Adapter\In\Event\NickBurstIrcopCacheSubscriber;
use App\OperServ\Adapter\Out\Persistence\Doctrine\BurstIrcopCache;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function count;

#[CoversClass(NickProtectionSubscriber::class)]
#[CoversClass(PendingNickActivities::class)]
#[CoversClass(IdentifiedUserVhostSyncService::class)]
#[CoversClass(NickProtectionService::class)]
#[CoversClass(NickBurstBatchStartedEvent::class)]
#[CoversClass(NickBurstBatchFinishedEvent::class)]
final class NickProtectionSubscriberTest extends TestCase
{
    private BurstState $burstState;

    private MockObject&NetworkUserLookupPort $networkUserLookup;

    private MockObject&NickNetworkActions $notifier;

    private NickProtectionSubscriber $subscriber;

    private Clock $clock;

    private RecordingSyncNetworkIdentificationHandler $syncNetworkIdentification;

    private DateTimeImmutable $now;

    #[Test]
    public function getSubscribedEventsReturnsAllHandlersWithPriorities(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $events = NickProtectionSubscriber::getSubscribedEvents();

        self::assertSame(
            [
                UserJoinedNetworkAppEvent::class => ['onUserJoined', 0],
                UserLeftNetworkEvent::class => ['onUserQuit', 0],
                UserNicknameChangedEvent::class => ['onNickChanged', 0],
                UserModesChangedEvent::class => ['onUserModeChanged', 0],
                NetworkSynchronizationCompletedEvent::class => ['onBurstComplete', -256],
                IrcMessageHandledEvent::class => ['onIrcMessageProcessed', -200],
                NetworkConnectionLostEvent::class => ['onConnectionLost', 0],
            ],
            $events,
        );
    }

    #[Test]
    public function onUserJoinedReturnsEarlyWhenSenderViewNotFound(): void
    {
        $dto = $this->createUserJoinedDTO('001ABC');
        $event = new UserJoinedNetworkAppEvent($dto);

        $this->networkUserLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn(null);

        $this->notifier->expects(self::never())->method('setUserVhost');

        $this->subscriber->onUserJoined($event);
    }

    #[Test]
    public function onUserJoinedAddsPendingWhenBurstNotComplete(): void
    {
        $dto = $this->createUserJoinedDTO('001ABC');
        $event = new UserJoinedNetworkAppEvent($dto);
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'Test',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $this->notifier->expects(self::never())->method('setUserVhost');

        $this->subscriber->onUserJoined($event);

        self::assertFalse($this->burstState->isComplete());
        $pending = $this->burstState->takePending();
        self::assertCount(1, $pending);
        self::assertSame($senderView->uid, $pending[0]->uid);
        self::assertSame($senderView->nick, $pending[0]->nick);
    }

    #[Test]
    public function onUserJoinedSyncsVhostAndRunsProtectionWhenBurstComplete(): void
    {
        $this->burstState->markComplete();
        $dto = $this->createUserJoinedDTO('001ABC');
        $event = new UserJoinedNetworkAppEvent($dto);
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'Test',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup
            ->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $this->notifier
            ->expects(self::atLeastOnce())
            ->method('setUserVhost')
            ->with('001ABC', '', '001');

        $this->subscriber->onUserJoined($event);
    }

    #[Test]
    public function onBurstCompleteMarksCompleteAndProcessesPendingUsers(): void
    {
        $senderView = new NetworkUser(
            uid: '001ABC',
            nick: 'Test',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );
        $this->burstState->addPending($senderView);

        $event = new NetworkSynchronizationCompletedEvent('001');

        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier
            ->expects(self::atLeastOnce())
            ->method('setUserVhost');

        $this->subscriber->onBurstComplete($event);

        self::assertTrue($this->burstState->isComplete());
        self::assertCount(0, $this->burstState->takePending());
    }

    #[Test]
    public function onBurstCompleteDoesNothingWhenNoPendingUsers(): void
    {
        $event = new NetworkSynchronizationCompletedEvent('001');

        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');

        $this->subscriber->onBurstComplete($event);

        self::assertTrue($this->burstState->isComplete());
    }

    #[Test]
    public function onBurstCompleteLoadsNicknamesInOneBatch(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->initializeSubscriber($nickRepository);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->burstState->addPending(new NetworkUser('001AAA', 'First', 'i', 'h', 'c', 'ip'));
        $this->burstState->addPending(new NetworkUser('001AAB', 'Second', 'i', 'h', 'c', 'ip'));
        $nickRepository->expects(self::once())->method('findByNicknamesLower')
            ->with(['First', 'Second'])->willReturn([]);
        $nickRepository->expects(self::never())->method('findByNick');
        $this->notifier->expects(self::exactly(2))->method('setUserVhost');

        $this->subscriber->onBurstComplete(new NetworkSynchronizationCompletedEvent('001'));

        self::assertTrue($this->burstState->isComplete());
        self::assertSame([], $this->burstState->takePending());
    }

    #[Test]
    public function onBurstCompleteUsesLoadedAccountForVhostAndIdentification(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $account = RegisteredNick::createPending('Alice', 'hash', 'alice@example.com', 'en', $this->now, $this->now);
        $account->activate();
        $account->changeVhost('alice.example');
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($account, 42);
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects(self::once())->method('findByNicknamesLower')->with(['ALICE'])->willReturn([$account]);
        $repo->expects(self::never())->method('findByNick');
        $repo->expects(self::once())->method('saveActivityBatch')->with([$account]);
        $publisher = $this->createMock(NickServEventPublisher::class);
        $publisher->expects(self::once())->method('publish')->with(self::callback(
            static fn ($event): bool => $event instanceof NickIdentifiedEvent
                && 42 === $event->nickId && '001AAA' === $event->uid && $event->implicit,
        ));
        $this->initializeSubscriber($repo, $publisher);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::once())->method('setUserVhost')->with('001AAA', 'alice.example', '001');
        $this->burstState->addPending(new NetworkUser('001AAA', 'ALICE', 'i', 'h', 'c', 'ip', true, false, '001'));

        $this->subscriber->onBurstComplete(new NetworkSynchronizationCompletedEvent('001'));

        self::assertSame($this->now, $account->getLastSeenAt());
    }

    #[Test]
    public function onBurstCompleteSavesIdentifiedActivityOncePerBatch(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $alice = RegisteredNick::createPending('Alice', 'hash', 'a@example.com', 'en', $this->now, $this->now);
        $bob = RegisteredNick::createPending('Bob', 'hash', 'b@example.com', 'en', $this->now, $this->now);
        $alice->activate();
        $bob->activate();
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($alice, 1);
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($bob, 2);
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNicknamesLower')->willReturn([$alice, $bob]);
        $repo->expects(self::never())->method('save');
        $repo->expects(self::once())->method('saveActivityBatch')->with([$alice, $bob]);
        $publisher = $this->createMock(NickServEventPublisher::class);
        $publisher->expects(self::exactly(2))->method('publish');
        $this->initializeSubscriber($repo, $publisher);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $this->burstState->addPending(new NetworkUser('001AAA', 'Alice', 'i', 'h', 'c', 'ip', true));
        $this->burstState->addPending(new NetworkUser('001AAB', 'Bob', 'i', 'h', 'c', 'ip', true));

        $this->subscriber->onBurstComplete(new NetworkSynchronizationCompletedEvent('001'));

        self::assertSame($this->now, $alice->getLastSeenAt());
        self::assertSame($this->now, $bob->getLastSeenAt());
    }

    #[Test]
    public function onBurstCompletePersistsActivityWhenAnIdentificationListenerFails(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $alice = RegisteredNick::createPending('Alice', 'hash', 'a@example.com', 'en', $this->now, $this->now);
        $alice->activate();
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($alice, 1);
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNicknamesLower')->willReturn([$alice]);
        $repo->expects(self::once())->method('saveActivityBatch')->with([$alice]);
        $publisher = $this->createMock(NickServEventPublisher::class);
        $publisher->expects(self::once())->method('publish')->willThrowException(new RuntimeException('listener failed'));
        $this->initializeSubscriber($repo, $publisher);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $this->burstState->addPending(new NetworkUser('001AAA', 'Alice', 'i', 'h', 'c', 'ip', true));

        $this->expectExceptionMessage('listener failed');
        $this->subscriber->onBurstComplete(new NetworkSynchronizationCompletedEvent('001'));
    }

    #[Test]
    public function onBurstCompletePublishesBoundedBatchLifecycle(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $alice = RegisteredNick::createPending('Alice', 'hash', 'a@example.com', 'en', $this->now, $this->now);
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($alice, 1);
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNicknamesLower')->willReturn([$alice]);
        $events = [];
        $publisher = $this->createMock(NickServEventPublisher::class);
        $publisher->expects(self::exactly(2))->method('publish')->willReturnCallback(
            static function ($event) use (&$events): void { $events[] = $event; },
        );
        $this->initializeSubscriber($repo, null, $publisher);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::once())->method('setUserVhost');
        $this->burstState->addPending(new NetworkUser('001AAA', 'Alice', 'i', 'h', 'c', 'ip'));

        $this->subscriber->onBurstComplete(new NetworkSynchronizationCompletedEvent('001'));

        self::assertInstanceOf(NickBurstBatchStartedEvent::class, $events[0]);
        self::assertSame([1], $events[0]->nickIds);
        self::assertInstanceOf(NickBurstBatchFinishedEvent::class, $events[1]);
        self::assertSame($events[0]->token, $events[1]->token);
    }

    #[Test]
    public function onBurstCompleteEndsBatchWhenPreloadingFails(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $events = [];
        $publisher = $this->createMock(NickServEventPublisher::class);
        $publisher->expects(self::exactly(2))->method('publish')->willReturnCallback(
            static function ($event) use (&$events): void {
                $events[] = $event;
                if ($event instanceof NickBurstBatchStartedEvent) {
                    throw new RuntimeException('preload failed');
                }
            },
        );
        $this->initializeSubscriber($this->createStub(RegisteredNickRepositoryInterface::class), null, $publisher);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $this->burstState->addPending(new NetworkUser('001AAA', 'Alice', 'i', 'h', 'c', 'ip'));

        try {
            $this->subscriber->onBurstComplete(new NetworkSynchronizationCompletedEvent('001'));
            self::fail('A preload failure must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('preload failed', $e->getMessage());
        }
        self::assertInstanceOf(NickBurstBatchStartedEvent::class, $events[0]);
        self::assertInstanceOf(NickBurstBatchFinishedEvent::class, $events[1]);
        self::assertSame($events[0]->token, $events[1]->token);
    }

    #[Test]
    public function burstLifecyclePrefetchesBothConsumersForEachIdentification(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $alice = RegisteredNick::createPending('Alice', 'hash', 'a@example.com', 'en', $this->now, $this->now);
        $bob = RegisteredNick::createPending('Bob', 'hash', 'b@example.com', 'en', $this->now, $this->now);
        $alice->activate();
        $bob->activate();
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($alice, 1);
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($bob, 2);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNicknamesLower')->willReturn([$alice, $bob]);
        $nickRepo->expects(self::once())->method('saveActivityBatch')->with([$alice, $bob]);
        $role = OperRole::create('Admin');
        $assignment = OperIrcop::create($this->now, 1, $role);
        $operRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $operRepo->expects(self::once())->method('findByNickIds')->with([1, 2])->willReturn([$assignment]);
        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::once())->method('countUnreadByTargetNickIds')->with([1, 2])->willReturn([1 => 3]);
        $operCache = new BurstIrcopCache();
        $memoCache = new BurstMemoNoticeCache();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new NickBurstIrcopCacheSubscriber($operRepo, $operCache));
        $dispatcher->addSubscriber(new NickBurstMemoCacheSubscriber($memoRepo, $memoCache));
        $observed = [];
        $dispatcher->addListener(
            NickIdentifiedEvent::class,
            static function (NickIdentifiedEvent $event) use (&$observed, $operCache, $memoCache): void {
                $observed[] = [$event->nickId, $operCache->lookup($event->nickId), $memoCache->countFor($event->nickId)];
            },
        );
        $bus = $this->createStub(EventBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $event) use ($dispatcher): void { $dispatcher->dispatch($event); });
        $publisher = new SymfonyNickServEventPublisher($bus);
        $this->initializeSubscriber($nickRepo, $publisher, $publisher);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $this->burstState->addPending(new NetworkUser('001AAA', 'Alice', 'i', 'h', 'c', 'ip', true));
        $this->burstState->addPending(new NetworkUser('001AAB', 'Bob', 'i', 'h', 'c', 'ip', true));

        $this->subscriber->onBurstComplete(new NetworkSynchronizationCompletedEvent('001'));

        self::assertSame([[1, [true, $assignment], 3], [2, [true, null], 0]], $observed);
        self::assertSame([false, null], $operCache->lookup(1));
        self::assertNull($memoCache->countFor(1));
    }

    #[Test]
    public function onBurstCompleteBoundsNicknameLookupBatches(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $sizes = [];
        $repo->expects(self::exactly(2))->method('findByNicknamesLower')->willReturnCallback(
            static function (array $nicknames) use (&$sizes): array {
                $sizes[] = count($nicknames);

                return [];
            },
        );
        $repo->expects(self::never())->method('findByNick');
        $this->initializeSubscriber($repo);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::exactly(201))->method('setUserVhost');
        for ($i = 0; $i < 201; ++$i) {
            $this->burstState->addPending(new NetworkUser((string) $i, 'Nick' . $i, 'i', 'h', 'c', 'ip'));
        }

        $this->subscriber->onBurstComplete(new NetworkSynchronizationCompletedEvent('001'));

        self::assertSame([200, 1], $sizes);
    }

    #[Test]
    public function onNickChangedDelegatesToNickProtectionServiceAndSyncsVhostWhenUserFound(): void
    {
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $this->notifier->expects(self::once())
            ->method('setUserVhost')
            ->with('001ABC', '', '001');

        $event = new UserNicknameChangedEvent('001ABC', 'OldNick', 'NewNick');

        $this->subscriber->onNickChanged($event);
    }

    #[Test]
    public function onUserModeChangedIgnoresDeltasWithoutTheIdentifiedMode(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $event = new UserModesChangedEvent('001ABC', '+i');
        $this->subscriber->onUserModeChanged($event);

        self::assertNull($this->syncNetworkIdentification->handled);
    }

    #[Test]
    public function onUserModeChangedDoesNothingWhenUserNotFound(): void
    {
        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn(null);

        $this->notifier->expects(self::never())->method('setUserVhost');
        $event = new UserModesChangedEvent('001ABC', '+r');
        $this->subscriber->onUserModeChanged($event);

        self::assertNull($this->syncNetworkIdentification->handled);
    }

    #[Test]
    public function onUserModeChangedDelegatesTheFinalDeidentifiedStateFromACombinedDelta(): void
    {
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'Test',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $this->notifier->expects(self::never())->method('setUserVhost');

        $event = new UserModesChangedEvent('001ABC', '+R-r');
        $this->subscriber->onUserModeChanged($event);

        $input = $this->syncNetworkIdentification->handled;
        self::assertNotNull($input);
        self::assertFalse($input->user->isIdentified);
        self::assertSame('001ABC', $input->user->uid);
        self::assertSame($this->now, $input->occurredAt);
    }

    #[Test]
    public function onUserModeChangedSyncsVhostAndEnforcesProtectionWhenIdentified(): void
    {
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'Test',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $this->notifier->expects(self::never())->method('setUserVhost');

        $event = new UserModesChangedEvent('001ABC', '-r+ir');
        $this->subscriber->onUserModeChanged($event);

        $input = $this->syncNetworkIdentification->handled;
        self::assertNotNull($input);
        self::assertTrue($input->user->isIdentified);
        self::assertSame('001ABC', $input->user->uid);
        self::assertSame($this->now, $input->occurredAt);
    }

    #[Test]
    public function syntheticMinusRWaitsForTheStandardProtocolNicknameChangeCleanup(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $identifiedSessions = new IdentifiedSessionRegistry();
        $identifiedSessions->register('001ABC', 'OldNick');
        $networkUser = new NetworkUser(
            '001ABC',
            'NewNick',
            'ident',
            'host',
            'cloak',
            'ip',
            isIdentified: false,
            serverSid: '001',
        );
        $senderView = new SenderView(
            '001ABC',
            'NewNick',
            'ident',
            'host',
            'cloak',
            'ip',
            isIdentified: false,
            serverSid: '001',
        );
        $networkUserLookup = $this->createStub(NetworkUserLookupPort::class);
        $networkUserLookup->method('findByUid')->willReturn($senderView);
        $applicationUserLookup = $this->createStub(NickNetworkUserLookup::class);
        $applicationUserLookup->method('findByUid')->willReturn($networkUser);
        $networkActions = $this->createMock(NickNetworkActions::class);
        $networkActions->expects(self::once())->method('setUserAccount')->with('001ABC', '0');
        $networkActions->expects(self::exactly(2))->method('setUserVhost')->with('001ABC', '', '001');
        $nickChangePolicy = $this->createStub(NickChangeIdentificationPolicy::class);
        $nickChangePolicy->method('preservesIdentification')->willReturn(false);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $syncNetworkIdentification = $this->createMock(SyncNetworkIdentificationHandlerInterface::class);
        $syncNetworkIdentification->expects(self::never())->method('handle');

        $nickProtection = new NickProtectionService(
            $nickRepository,
            $applicationUserLookup,
            $networkActions,
            $this->burstState,
            $identifiedSessions,
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $nickChangePolicy,
            $this->guestNicknameGenerator(),
            $this->createStub(IssueMigrationRescueTokenHandlerInterface::class),
            $this->serviceAuthenticationMode(),
        );
        $vhostSync = new IdentifiedUserVhostSyncService(
            $nickRepository,
            $networkActions,
            new VhostDisplayResolver(),
            $this->createStub(ForcedVhostCheckerInterface::class),
            $nickChangePolicy,
        );
        $subscriber = new NickProtectionSubscriber(
            $nickProtection,
            $vhostSync,
            $this->burstState,
            $networkUserLookup,
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $syncNetworkIdentification,
        );
        $this->burstState->markComplete();

        $subscriber->onUserModeChanged(new UserModesChangedEvent('001ABC', '-r'));
        self::assertSame('OldNick', $identifiedSessions->findNick('001ABC'));

        $subscriber->onNickChanged(new UserNicknameChangedEvent('001ABC', 'OldNick', 'NewNick'));
        self::assertNull($identifiedSessions->findNick('001ABC'));
    }

    #[Test]
    public function standardProtocolPlusRRetainsTheExistingVhostAndProtectionFlow(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            '001ABC',
            'Test',
            'ident',
            'host',
            'cloak',
            'ip',
            isIdentified: true,
            serverSid: '001',
        );
        $networkUserLookup = $this->createMock(NetworkUserLookupPort::class);
        $networkUserLookup->expects(self::once())->method('findByUid')->with('001ABC')->willReturn($senderView);
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepository->expects(self::exactly(2))->method('findByNick')->with('Test')->willReturn(null);
        $pendingProtection = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingProtection->expects(self::once())->method('cancel')->with('001ABC');
        $syncNetworkIdentification = $this->createMock(SyncNetworkIdentificationHandlerInterface::class);
        $syncNetworkIdentification->expects(self::never())->method('handle');
        $nickChangePolicy = $this->createStub(NickChangeIdentificationPolicy::class);
        $networkActions = $this->createStub(NickNetworkActions::class);
        $burstState = new BurstState();
        $burstState->markComplete();
        $nickProtection = new NickProtectionService(
            $nickRepository,
            $this->createStub(NickNetworkUserLookup::class),
            $networkActions,
            $burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $this->createStub(PendingNickRestoreRegistryInterface::class),
            $this->createStub(NickProtectionNotifier::class),
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $nickChangePolicy,
            $this->guestNicknameGenerator(),
            $this->createStub(IssueMigrationRescueTokenHandlerInterface::class),
            $this->serviceAuthenticationMode(),
        );
        $vhostSync = new IdentifiedUserVhostSyncService(
            $nickRepository,
            $networkActions,
            new VhostDisplayResolver(),
            $this->createStub(ForcedVhostCheckerInterface::class),
            $nickChangePolicy,
        );
        $subscriber = new NickProtectionSubscriber(
            $nickProtection,
            $vhostSync,
            $burstState,
            $networkUserLookup,
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $syncNetworkIdentification,
            $pendingProtection,
        );

        $subscriber->onUserModeChanged(new UserModesChangedEvent('001ABC', '+r'));
    }

    #[Test]
    public function standardProtocolPlusRStopsWhenTheNetworkUserIsUnavailable(): void
    {
        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn(null);
        $this->notifier->expects(self::never())->method('setUserVhost');
        $pendingProtection = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingProtection->expects(self::once())->method('cancel')->with('001ABC');
        $syncNetworkIdentification = $this->createMock(SyncNetworkIdentificationHandlerInterface::class);
        $syncNetworkIdentification->expects(self::never())->method('handle');
        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $syncNetworkIdentification,
            $pendingProtection,
        );

        $subscriber->onUserModeChanged(new UserModesChangedEvent('001ABC', '+r'));
    }

    #[Test]
    public function onUserQuitDelegatesToNickProtectionService(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $event = new UserLeftNetworkEvent(
            '001ABC',
            'User',
            'Quit reason',
            'ident',
            'display.host',
        );

        $this->subscriber->onUserQuit($event);
    }

    #[Test]
    public function quitsArePersistedOnceAtTheEndOfTheIrcLine(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $alice = RegisteredNick::createPending('Alice', 'hash', 'a@example.com', 'en', $this->now, $this->now);
        $bob = RegisteredNick::createPending('Bob', 'hash', 'b@example.com', 'en', $this->now, $this->now);
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($alice, 1);
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($bob, 2);
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturnMap([['Alice', $alice], ['Bob', $bob]]);
        $repo->expects(self::never())->method('save');
        $repo->expects(self::once())->method('saveActivityBatch')->with([$alice, $bob]);
        $this->initializeSubscriber($repo);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');

        $this->subscriber->onUserQuit(new UserLeftNetworkEvent('001AAA', 'Alice', 'split', 'a', 'host'));
        $this->subscriber->onUserQuit(new UserLeftNetworkEvent('001AAB', 'Bob', 'split', 'b', 'host'));
        $this->subscriber->onUserQuit(new UserLeftNetworkEvent('001AAC', 'Alice', 'later', 'a', 'host'));
        self::assertSame('later (a@host)', $alice->getLastQuitMessage());
        self::assertSame('split (b@host)', $bob->getLastQuitMessage());
        $this->subscriber->onIrcMessageProcessed(new IrcMessageHandledEvent());
        $this->subscriber->onIrcMessageProcessed(new IrcMessageHandledEvent());
    }

    #[Test]
    public function connectionLossFlushesAnInterruptedLinesQuitActivities(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $alice = RegisteredNick::createPending('Alice', 'hash', 'a@example.com', 'en', $this->now, $this->now);
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($alice, 1);
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($alice);
        $repo->expects(self::never())->method('save');
        $repo->expects(self::once())->method('saveActivityBatch')->with([$alice]);
        $this->initializeSubscriber($repo);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');

        $this->subscriber->onUserQuit(new UserLeftNetworkEvent('001AAA', 'Alice', 'split', 'a', 'host'));
        $this->subscriber->onConnectionLost(new NetworkConnectionLostEvent());
        $this->subscriber->onConnectionLost(new NetworkConnectionLostEvent());
    }

    #[Test]
    public function failedQuitFlushIsNotRetriedOnConnectionLoss(): void
    {
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $alice = RegisteredNick::createPending('Alice', 'hash', 'a@example.com', 'en', $this->now, $this->now);
        new ReflectionProperty(RegisteredNick::class, 'id')->setValue($alice, 1);
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->method('findByNick')->willReturn($alice);
        $repo->expects(self::once())->method('saveActivityBatch')->willThrowException(new RuntimeException('write failed'));
        $this->initializeSubscriber($repo);
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $this->notifier->expects(self::never())->method('setUserVhost');
        $this->subscriber->onUserQuit(new UserLeftNetworkEvent('001AAA', 'Alice', 'split'));

        try {
            $this->subscriber->onIrcMessageProcessed(new IrcMessageHandledEvent());
            self::fail('The persistence failure must propagate.');
        } catch (RuntimeException $e) {
            self::assertSame('write failed', $e->getMessage());
        }
        $this->subscriber->onConnectionLost(new NetworkConnectionLostEvent());
    }

    #[Test]
    public function onNickChangedDefersProtectionCheckWhenProtocolPreservesIdentificationAndUserNotIdentified(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('schedule')->with('001ABC', $this->now);

        $module = $this->createStub(NickProtectionTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $this->clock,
            $this->createStub(SyncNetworkIdentificationHandlerInterface::class),
            $pendingRegistry,
        );

        $event = new UserNicknameChangedEvent('001ABC', 'OldNick', 'NewNick');

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onUserJoinedDefersProtectionCheckWhenProtocolPreservesIdentificationAndUserNotIdentified(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('schedule')->with('001ABC', $this->now);

        $module = $this->createStub(NickProtectionTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $burstState = new BurstState();
        $burstState->markComplete();

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $this->clock,
            $this->createStub(SyncNetworkIdentificationHandlerInterface::class),
            $pendingRegistry,
        );

        $dto = $this->createUserJoinedDTO('001ABC');
        $subscriber->onUserJoined(new UserJoinedNetworkAppEvent($dto));
    }

    #[Test]
    public function onUserJoinedWhenIdentifiedDoesNotDeferProtectionCheck(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::never())->method('schedule');

        $burstState = new BurstState();
        $burstState->markComplete();

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $this->createStub(SyncNetworkIdentificationHandlerInterface::class),
            $pendingRegistry,
        );

        $dto = $this->createUserJoinedDTO('001ABC');
        $subscriber->onUserJoined(new UserJoinedNetworkAppEvent($dto));
    }

    #[Test]
    public function onNickChangedWhenIdentifiedDoesNotDeferProtectionCheck(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::never())->method('schedule');

        $burstState = new BurstState();
        $burstState->markComplete();

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $this->createStub(SyncNetworkIdentificationHandlerInterface::class),
            $pendingRegistry,
        );

        $event = new UserNicknameChangedEvent('001ABC', 'OldNick', 'NewNick');

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onNickChangedWhenIdentifiedAndProtocolPreservesIdentificationDoesNotSyncVhost(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $senderView = new SenderView(
            uid: '001ABC',
            nick: 'NewNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn($senderView);

        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::never())->method('schedule');

        $module = $this->createStub(NickProtectionTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);

        $burstState = new BurstState();
        $burstState->markComplete();

        $notifier = $this->createMock(NickNetworkActions::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $account = RegisteredNick::createPending(
            'NewNick',
            'hash',
            'u@e.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
        );
        $account->activate();
        $account->changeVhost('test.vhost');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($account);

        $nickChangePolicy = $this->createStub(NickChangeIdentificationPolicy::class);
        $nickChangePolicy->method('preservesIdentification')->willReturn(true);
        $vhostSync = new IdentifiedUserVhostSyncService(
            $nickRepo,
            $notifier,
            new VhostDisplayResolver(),
            $this->createStub(ForcedVhostCheckerInterface::class),
            $nickChangePolicy,
        );

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $vhostSync,
            $burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $this->clock,
            $this->createStub(SyncNetworkIdentificationHandlerInterface::class),
            $pendingRegistry,
        );

        $event = new UserNicknameChangedEvent('001ABC', 'OldNick', 'NewNick');

        $subscriber->onNickChanged($event);
    }

    #[Test]
    public function onUserModeChangedCancelsPendingProtectionCheck(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('cancel')->with('001ABC');

        $this->networkUserLookup->expects(self::once())
            ->method('findByUid')
            ->with('001ABC')
            ->willReturn(null);

        $nativeModule = $this->createStub(NativeAuthenticationTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($nativeModule);

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $this->clock,
            $this->createStub(SyncNetworkIdentificationHandlerInterface::class),
            $pendingRegistry,
        );

        $event = new UserModesChangedEvent('001ABC', '+r');
        $subscriber->onUserModeChanged($event);
    }

    #[Test]
    public function onUserQuitCancelsPendingProtectionCheck(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $this->networkUserLookup->expects(self::never())->method('findByUid');
        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('cancel')->with('001ABC');

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $this->createStub(SyncNetworkIdentificationHandlerInterface::class),
            $pendingRegistry,
        );

        $event = new UserLeftNetworkEvent(
            '001ABC',
            'User',
            'Quit reason',
            'ident',
            'display.host',
        );

        $subscriber->onUserQuit($event);
    }

    #[Test]
    public function onIrcMessageProcessedEnforcesProtectionOnExpiredUids(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $pendingRegistry = $this->createMock(PendingNickProtectionRegistryInterface::class);
        $pendingRegistry->expects(self::once())->method('flushExpired')->with($this->now)->willReturn(['001EXPIRED', '001NOTFOUND', '001IDENTIFIED']);

        $expiredUser = new SenderView(
            uid: '001EXPIRED',
            nick: 'TargetNick',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
            serverSid: '001',
        );

        $identifiedUser = new SenderView(
            uid: '001IDENTIFIED',
            nick: 'TargetNick2',
            ident: 'test',
            hostname: 'host',
            cloakedHost: 'cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            serverSid: '001',
        );

        $this->networkUserLookup->expects(self::exactly(3))
            ->method('findByUid')
            ->willReturnMap([
                ['001EXPIRED', $expiredUser],
                ['001NOTFOUND', null],
                ['001IDENTIFIED', $identifiedUser],
            ]);

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $this->createStub(SyncNetworkIdentificationHandlerInterface::class),
            $pendingRegistry,
        );

        $subscriber->onIrcMessageProcessed(new IrcMessageHandledEvent());
    }

    #[Test]
    public function onIrcMessageProcessedDoesNothingWhenNoRegistry(): void
    {
        $this->notifier->expects(self::never())->method('setUserVhost');
        $this->networkUserLookup->expects(self::never())->method('findByUid');

        $subscriber = new NickProtectionSubscriber(
            $this->createNickProtectionService(),
            $this->createVhostSyncService(),
            $this->burstState,
            $this->networkUserLookup,
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $this->clock,
            $this->createStub(SyncNetworkIdentificationHandlerInterface::class),
        );

        $subscriber->onIrcMessageProcessed(new IrcMessageHandledEvent());
    }

    protected function setUp(): void
    {
        $this->initializeSubscriber($this->createStub(RegisteredNickRepositoryInterface::class));
    }

    private function initializeSubscriber(RegisteredNickRepositoryInterface $nickRepository, ?NickServEventPublisher $publisher = null, ?NickServEventPublisher $batchPublisher = null): void
    {
        $this->burstState = new BurstState();
        $this->networkUserLookup = $this->createMock(NetworkUserLookupPort::class);
        $this->notifier = $this->createMock(NickNetworkActions::class);
        $this->now = new DateTimeImmutable('2026-01-02 03:04:05');
        $this->clock = $this->createStub(Clock::class);
        $this->clock->method('now')->willReturn($this->now);
        $this->syncNetworkIdentification = new RecordingSyncNetworkIdentificationHandler();

        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $translator = $this->createStub(NickProtectionNotifier::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);

        $nickProtectionService = new NickProtectionService(
            $nickRepository,
            $userLookup,
            $this->notifier,
            $this->burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $translator,
            $publisher ?? $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
            $this->createStub(IssueMigrationRescueTokenHandlerInterface::class),
            $this->serviceAuthenticationMode(),
        );

        $ircopRepository = $this->createStub(ForcedVhostCheckerInterface::class);

        $nickChangePolicy = $this->createStub(NickChangeIdentificationPolicy::class);
        $vhostSync = new IdentifiedUserVhostSyncService(
            $nickRepository,
            $this->notifier,
            new VhostDisplayResolver(),
            $ircopRepository,
            $nickChangePolicy,
        );

        $nativeModule = $this->createStub(NativeAuthenticationTestProtocolModule::class);
        $connectionHolder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($nativeModule);

        $this->subscriber = new NickProtectionSubscriber(
            $nickProtectionService,
            $vhostSync,
            $this->burstState,
            $this->networkUserLookup,
            $connectionHolder,
            $this->clock,
            $this->syncNetworkIdentification,
            batchEventPublisher: $batchPublisher,
        );
    }

    private function createVhostSyncService(): IdentifiedUserVhostSyncService
    {
        return new IdentifiedUserVhostSyncService(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(NickNetworkActions::class),
            new VhostDisplayResolver(),
            $this->createStub(ForcedVhostCheckerInterface::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
        );
    }

    private function createNickProtectionService(): NickProtectionService
    {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NickNetworkUserLookup::class);
        $translator = $this->createStub(NickProtectionNotifier::class);
        $pendingRegistry = $this->createStub(PendingNickRestoreRegistryInterface::class);

        return new NickProtectionService(
            $nickRepository,
            $userLookup,
            $this->createStub(NickNetworkActions::class),
            $this->burstState,
            new IdentifiedSessionRegistry(),
            new SessionLanguageRegistry(),
            $pendingRegistry,
            $translator,
            $this->createStub(NickServEventPublisher::class),
            $this->createStub(ForbiddenNickService::class),
            $this->createStub(NickChangeIdentificationPolicy::class),
            $this->guestNicknameGenerator(),
            $this->createStub(IssueMigrationRescueTokenHandlerInterface::class),
            $this->serviceAuthenticationMode(),
        );
    }

    private function createUserJoinedDTO(string $uid): UserJoinedNetworkDTO
    {
        return new UserJoinedNetworkDTO(
            uid: $uid,
            nick: 'Test',
            ident: 'test',
            hostname: 'host.example',
            cloakedHost: 'cloak.example',
            ipBase64: 'dGVzdA==',
            displayHost: 'cloak.example',
            isIdentified: false,
            isOper: false,
            serverSid: '001',
        );
    }

    private function serviceAuthenticationMode(): NicknameAuthenticationModeQuery
    {
        $mode = $this->createStub(NicknameAuthenticationModeQuery::class);
        $mode->method('current')->willReturn(NicknameAuthenticationMode::ServiceCommand);

        return $mode;
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

interface NickProtectionTestProtocolModule extends ProtocolModuleInterface, NickChangePreservesIdentificationInterface {}

interface NativeAuthenticationTestProtocolModule extends ProtocolModuleInterface, NativeNicknameAuthenticationInterface {}

final class RecordingSyncNetworkIdentificationHandler implements SyncNetworkIdentificationHandlerInterface
{
    public ?SyncNetworkIdentification $handled = null;

    public function handle(SyncNetworkIdentification $command): void
    {
        $this->handled = $command;
    }
}
