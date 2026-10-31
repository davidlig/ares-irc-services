<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Integration;

use App\ChanServ\Adapter\In\Event\ChanServAkickEnforceSubscriber;
use App\ChanServ\Adapter\In\Irc\Bot\ChanServBot;
use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanAuthorizationContextInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\ChanServService;
use App\ChanServ\Adapter\In\Irc\ChanServUserPresentationPreferences;
use App\ChanServ\Adapter\In\Irc\Command\UnbanCommand;
use App\ChanServ\Adapter\Out\Network\IrcAkickActions;
use App\ChanServ\Adapter\Out\Network\IrcChannelBanActions;
use App\ChanServ\Adapter\Out\Network\IrcChannelBanNetworkQuery;
use App\ChanServ\Adapter\Out\Network\IrcChannelEntryNetworkQuery;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandler;
use App\ChanServ\Application\UseCase\EnforceAkick\SynchronizeAllChannelAkicksHandler;
use App\ChanServ\Application\UseCase\EnforceAkick\SynchronizeAllChannelAkicksHandlerInterface;
use App\ChanServ\Application\UseCase\Unban\UnbanChannel;
use App\ChanServ\Application\UseCase\Unban\UnbanChannelHandler;
use App\ChanServ\Application\UseCase\Unban\UnbanChannelOutcome;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\In\Event\CoreApplyOutgoingChannelKickAdapter;
use App\Irc\Adapter\In\Event\CoreApplyOutgoingChannelModesAdapter;
use App\Irc\Adapter\Network\ChannelModeStateSynchronizer;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\ChannelBanMatcherProvider;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdChannelBanMatcher;
use App\Irc\Adapter\ServiceBridge\CoreChannelBanMatchQuery;
use App\Irc\Adapter\ServiceBridge\CoreChannelLookupAdapter;
use App\Irc\Adapter\ServiceBridge\CoreNetworkUserLookupAdapter;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\BurstCompletePort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceChannelRegistrationPort;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Irc\Application\Port\In\ServiceUidGeneratorInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\Irc\Application\PublishedEvent\ServiceIntroductionRequestedEvent;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\Shared\Application\Port\EventBusInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_values;

#[CoversClass(ChannelModeStateSynchronizer::class)]
#[CoversClass(UnbanChannelHandler::class)]
#[CoversClass(EnforceChannelAkickHandler::class)]
final class AkickEnforcementUnbanIntegrationTest extends TestCase
{
    private InMemoryChannelRepository $channelRepository;

    private InMemoryNetworkUserRepository $userRepository;

    private ActiveConnectionHolder $connectionHolder;

    private ChanServBot $chanServBot;

    private CoreChannelLookupAdapter $channelLookup;

    private CoreNetworkUserLookupAdapter $userLookup;

    private IrcChannelBanNetworkQuery $banNetworkQuery;

    private IrcChannelBanActions $banActions;

    private IrcAkickActions $akickActions;

    private IrcChannelEntryNetworkQuery $entryNetworkQuery;

    private ProtocolServiceActionsInterface $protocolServiceActions;

    /** @var list<array{sid: string, channel: string, modeStr: string, params: array<int, string>, serviceUid: string}> */
    private array $recordedModeChanges = [];

    /** @var list<array{sid: string, channel: string, targetUid: string, reason: string, serviceUid: string}> */
    private array $recordedKicks = [];

    /** @var list<string> */
    private array $sentMessages = [];

    /** @var list<array{channel: string, message: string}> */
    private array $sentChannelNotices = [];

    #[Test]
    public function akickEnforcementOnJoinRecordsBanAndUnbanRemovesIt(): void
    {
        $now = new DateTimeImmutable('2026-10-30T12:00:00Z');
        $channelName = '#test';

        // 1. Setup channel and user in network state
        $ircChannel = new Channel(new ChannelName($channelName), '+nt', $now);
        $this->channelRepository->save($ircChannel);

        $user = new NetworkUser(
            new Uid('001BAD'),
            new Nick('BadUser'),
            new Ident('bad'),
            'bad.host',
            'bad.host',
            'bad.host',
            '+r',
            $now,
            'Bad User',
            '001',
            '192.0.2.100',
        );
        $this->userRepository->add($user);

        // 2. Setup registered channel and AKICK rule
        $registeredChannel = RegisteredChannel::register($now, $channelName, 1, 'Test channel');
        $prop = new ReflectionProperty(RegisteredChannel::class, 'id');
        $prop->setValue($registeredChannel, 10);

        $akickRule = ChannelAkick::create($now, 10, 1, '*!*@bad.host', 'Spamming detected');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($registeredChannel);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akickRule]);

        $enforceHandler = new EnforceChannelAkickHandler(
            $channelRepo,
            $akickRepo,
            $this->entryNetworkQuery,
            $this->akickActions,
            new AkickMatchPolicy(),
        );

        $subscriber = new ChanServAkickEnforceSubscriber(
            $enforceHandler,
            $this->createStub(SynchronizeAllChannelAkicksHandlerInterface::class),
        );

        // 3. User joins channel -> triggers AKICK enforcement
        $subscriber->onUserJoined(new UserJoinedChannelEvent('001BAD', $channelName));

        $modeChanges = $this->getRecordedModeChanges();
        self::assertCount(1, $modeChanges);
        self::assertSame([
            'sid' => '001',
            'channel' => $channelName,
            'modeStr' => '+b',
            'params' => ['*!*@bad.host'],
            'serviceUid' => '001CSRV',
        ], $modeChanges[0]);

        self::assertCount(1, $this->recordedKicks);
        self::assertSame([
            'sid' => '001',
            'channel' => $channelName,
            'targetUid' => '001BAD',
            'reason' => 'Spamming detected',
            'serviceUid' => '001CSRV',
        ], $this->recordedKicks[0]);

        // 4. Verify ban was immediately recorded in channel state and user was kicked from member list
        $networkChannel = $this->channelRepository->findByName(new ChannelName($channelName));
        self::assertNotNull($networkChannel);
        self::assertSame(['*!*@bad.host'], $networkChannel->getBans());
        self::assertFalse($networkChannel->isMember(new Uid('001BAD')));

        // 5. User invokes UNBAN -> verify ban is matched and removed
        $unbanHandler = new UnbanChannelHandler(
            $channelRepo,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->banNetworkQuery,
            $this->banActions,
        );

        $result = $unbanHandler->handle(new UnbanChannel($channelName, 1, '001BAD', true));

        self::assertSame(UnbanChannelOutcome::Removed, $result->outcome);
        self::assertSame(1, $result->removedCount);

        $modeChangesAfterUnban = $this->getRecordedModeChanges();
        self::assertCount(2, $modeChangesAfterUnban);
        self::assertSame([
            'sid' => '001',
            'channel' => $channelName,
            'modeStr' => '-b',
            'params' => ['*!*@bad.host'],
            'serviceUid' => '001CSRV',
        ], $modeChangesAfterUnban[1]);

        // 6. Verify channel state has 0 bans
        self::assertSame([], $networkChannel->getBans());
    }

    #[Test]
    public function akickEnforcementOnSyncRecordsBanAndUnbanRemovesIt(): void
    {
        $now = new DateTimeImmutable('2026-10-30T12:00:00Z');
        $channelName = '#syncchan';

        // 1. Setup channel with member already present
        $ircChannel = new Channel(new ChannelName($channelName), '+nt', $now);
        $ircChannel->syncMember(new Uid('001EVIL'), ChannelMemberRole::None, []);
        $this->channelRepository->save($ircChannel);

        $user = new NetworkUser(
            new Uid('001EVIL'),
            new Nick('EvilUser'),
            new Ident('evil'),
            'evil.org',
            'evil.org',
            'evil.org',
            '+r',
            $now,
            'Evil User',
            '001',
            '192.0.2.200',
        );
        $this->userRepository->add($user);

        // 2. Setup registered channel and AKICK rule
        $registeredChannel = RegisteredChannel::register($now, $channelName, 2, 'Sync Channel');
        $prop = new ReflectionProperty(RegisteredChannel::class, 'id');
        $prop->setValue($registeredChannel, 20);

        $akickRule = ChannelAkick::create($now, 20, 2, '*!*@evil.org', 'Sync akick');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($registeredChannel);
        $channelRepo->method('iterateAll')->willReturn([$registeredChannel]);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akickRule]);

        $enforceHandler = new EnforceChannelAkickHandler(
            $channelRepo,
            $akickRepo,
            $this->entryNetworkQuery,
            $this->akickActions,
            new AkickMatchPolicy(),
        );

        $syncHandler = new SynchronizeAllChannelAkicksHandler(
            $channelRepo,
            $this->entryNetworkQuery,
            $enforceHandler,
        );

        $subscriber = new ChanServAkickEnforceSubscriber(
            $enforceHandler,
            $syncHandler,
        );

        // 3. Network synchronization completes
        $subscriber->onSyncComplete(new NetworkSynchronizationCompletedEvent('001'));

        $modeChanges = $this->getRecordedModeChanges();
        self::assertCount(1, $modeChanges);
        self::assertSame([
            'sid' => '001',
            'channel' => $channelName,
            'modeStr' => '+b',
            'params' => ['*!*@evil.org'],
            'serviceUid' => '001CSRV',
        ], $modeChanges[0]);

        self::assertCount(1, $this->recordedKicks);
        self::assertSame([
            'sid' => '001',
            'channel' => $channelName,
            'targetUid' => '001EVIL',
            'reason' => 'Sync akick',
            'serviceUid' => '001CSRV',
        ], $this->recordedKicks[0]);

        // 4. Verify ban recorded in channel
        $networkChannel = $this->channelRepository->findByName(new ChannelName($channelName));
        self::assertNotNull($networkChannel);
        self::assertSame(['*!*@evil.org'], $networkChannel->getBans());

        // 5. UNBAN via UnbanChannelHandler
        $unbanHandler = new UnbanChannelHandler(
            $channelRepo,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->banNetworkQuery,
            $this->banActions,
        );

        $result = $unbanHandler->handle(new UnbanChannel($channelName, 2, '001EVIL', true));

        self::assertSame(UnbanChannelOutcome::Removed, $result->outcome);
        self::assertSame(1, $result->removedCount);

        $modeChangesAfterUnban = $this->getRecordedModeChanges();
        self::assertCount(2, $modeChangesAfterUnban);
        self::assertSame([
            'sid' => '001',
            'channel' => $channelName,
            'modeStr' => '-b',
            'params' => ['*!*@evil.org'],
            'serviceUid' => '001CSRV',
        ], $modeChangesAfterUnban[1]);

        self::assertSame([], $networkChannel->getBans());
    }

    #[Test]
    public function endToEndAkickFollowedByChanServServiceUnbanDispatchesDoneMessage(): void
    {
        $now = new DateTimeImmutable('2026-10-30T12:00:00Z');
        $channelName = '#flow';

        $ircChannel = new Channel(new ChannelName($channelName), '+nt', $now);
        $this->channelRepository->save($ircChannel);

        $user = new NetworkUser(
            new Uid('001USER'),
            new Nick('TargetUser'),
            new Ident('target'),
            'target.net',
            'target.net',
            'target.net',
            '+r',
            $now,
            'Target User',
            '001',
            '192.0.2.50',
        );
        $this->userRepository->add($user);

        $registeredChannel = RegisteredChannel::register($now, $channelName, 1, 'Flow Channel');
        $prop = new ReflectionProperty(RegisteredChannel::class, 'id');
        $prop->setValue($registeredChannel, 30);

        $akickRule = ChannelAkick::create($now, 30, 1, '*!*@target.net', 'Flow reason');

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByChannelName')->willReturn($registeredChannel);

        $akickRepo = $this->createStub(ChannelAkickRepositoryInterface::class);
        $akickRepo->method('listByChannel')->willReturn([$akickRule]);

        $enforceHandler = new EnforceChannelAkickHandler(
            $channelRepo,
            $akickRepo,
            $this->entryNetworkQuery,
            $this->akickActions,
            new AkickMatchPolicy(),
        );

        $subscriber = new ChanServAkickEnforceSubscriber(
            $enforceHandler,
            $this->createStub(SynchronizeAllChannelAkicksHandlerInterface::class),
        );

        // 1. Enforce AKICK on join
        $subscriber->onUserJoined(new UserJoinedChannelEvent('001USER', $channelName));

        // Channel now has the ban recorded
        self::assertSame(['*!*@target.net'], $ircChannel->getBans());

        // 2. Execute UNBAN via ChanServService
        $unbanHandler = new UnbanChannelHandler(
            $channelRepo,
            new ChanServAccessHelper(
                $this->createStub(ChannelAccessRepositoryInterface::class),
                $this->createStub(ChannelLevelRepositoryInterface::class),
            ),
            $this->banNetworkQuery,
            $this->banActions,
        );

        $commandRegistry = new ChanServCommandRegistry([new UnbanCommand($unbanHandler)]);

        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $uid, string $message): void {
            $this->sentMessages[] = $message;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(function (string $channel, string $message): void {
            $this->sentChannelNotices[] = ['channel' => $channel, 'message' => $message];
        });

        $accountPort = $this->createStub(ChanUserAccountPort::class);
        $accountPort->method('findAccountByNick')->willReturn(new ChanAccountView(1, 'TargetUser', 'en'));

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id);

        $authChecker = $this->createStub(ChanAuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturnCallback(
            static fn (string $perm, ChanServContext $ctx): bool => 'IDENTIFIED' === $perm && $ctx->isSenderIdentified(),
        );

        $service = new ChanServService(
            commandRegistry: $commandRegistry,
            channelRepository: $channelRepo,
            accountPort: $accountPort,
            preferences: $this->createStub(ChanServUserPresentationPreferences::class),
            notifier: $notifier,
            translator: $translator,
            channelLookup: $this->channelLookup,
            modeSupportProvider: $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            userLookup: $this->userLookup,
            authorizationContext: $this->createStub(ChanAuthorizationContextInterface::class),
            authorizationChecker: $authChecker,
            eventDispatcher: $this->createStub(EventBusInterface::class),
            commandAudit: $this->createStub(CommandAuditRecorder::class),
            serviceNicks: new ServiceNicknameRegistry([]),
        );

        $sender = new SenderView('001USER', 'TargetUser', 'target', 'target.net', 'target.net', '*', true, false);

        $service->dispatch('UNBAN ' . $channelName, $sender);

        // Verify that unban.done message was sent (NOT unban.no_matches)
        self::assertCount(1, $this->sentMessages);
        self::assertStringContainsString('unban.done', $this->sentMessages[0]);
        self::assertNotEmpty($this->sentChannelNotices);
        self::assertSame($channelName, $this->sentChannelNotices[0]['channel']);

        // Channel ban is removed
        self::assertSame([], $ircChannel->getBans());
    }

    protected function setUp(): void
    {
        $this->channelRepository = new InMemoryChannelRepository();
        $this->userRepository = new InMemoryNetworkUserRepository();
        $this->connectionHolder = new ActiveConnectionHolder();
        $this->connectionHolder->onBurstComplete(new NetworkBurstCompleteEvent(
            $this->createStub(ConnectionInterface::class),
            '001',
        ));

        $this->recordedModeChanges = [];
        $this->recordedKicks = [];
        $this->sentMessages = [];
        $this->sentChannelNotices = [];

        $this->protocolServiceActions = $this->createStub(ProtocolServiceActionsInterface::class);
        $this->protocolServiceActions->method('setChannelModes')->willReturnCallback(
            function (string $serverSid, string $channelName, string $modeStr, array $params = [], string $serviceUid = ''): void {
                /** @var list<string> $modeParams */
                $modeParams = array_values($params);
                $this->recordedModeChanges[] = [
                    'sid' => $serverSid,
                    'channel' => $channelName,
                    'modeStr' => $modeStr,
                    'params' => $modeParams,
                    'serviceUid' => $serviceUid,
                ];
            },
        );
        $this->protocolServiceActions->method('kickFromChannel')->willReturnCallback(
            function (string $serverSid, string $channelName, string $targetUid, string $reason, string $serviceUid = ''): void {
                $this->recordedKicks[] = [
                    'sid' => $serverSid,
                    'channel' => $channelName,
                    'targetUid' => $targetUid,
                    'reason' => $reason,
                    'serviceUid' => $serviceUid,
                ];
            },
        );

        $protocolModule = $this->createStubForIntersectionOfInterfaces([
            ProtocolModuleInterface::class,
            ChannelBanMatcherProvider::class,
        ]);
        $protocolModule->method('getChannelBanMatcher')->willReturn(new InspIRCdChannelBanMatcher());
        $protocolModule->method('getServiceActions')->willReturn($this->protocolServiceActions);
        self::assertInstanceOf(ProtocolModuleInterface::class, $protocolModule);
        $this->connectionHolder->setProtocolModule($protocolModule);

        $modeSupport = $this->createStub(ChannelModeSupportInterface::class);
        $modeSupport->method('getListModeLetters')->willReturn(['b', 'e', 'I']);
        $modeSupport->method('getChannelSettingModesWithParamOnSet')->willReturn(['k', 'l']);
        $modeSupport->method('getChannelSettingModesUnsetWithParam')->willReturn(['k']);

        $modeSupportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $modeSupportProvider->method('getSupport')->willReturn($modeSupport);

        $enricher = new NetworkEventEnricher(
            $this->channelRepository,
            $this->userRepository,
            new EventDispatcher(),
            $this->createStub(SkipIdentifiedModeStripRegistry::class),
            $modeSupportProvider,
            $this->connectionHolder,
        );

        $applyOutgoing = new CoreApplyOutgoingChannelModesAdapter($enricher);
        $applyOutgoingKick = new CoreApplyOutgoingChannelKickAdapter($enricher);
        $this->channelLookup = new CoreChannelLookupAdapter($this->channelRepository);
        $this->userLookup = new CoreNetworkUserLookupAdapter($this->userRepository);

        $uidGen = $this->createStub(ServiceUidGeneratorInterface::class);
        $uidGen->method('generateUid')->willReturn('001CSRV');

        $this->chanServBot = new ChanServBot(
            $this->connectionHolder,
            $this->channelLookup,
            $applyOutgoing,
            $this->createStub(ServiceChannelRegistrationPort::class),
            $this->createStub(SendNoticePort::class),
            $uidGen,
            'services.example.com',
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            'ChanServ',
            'ChanServ',
            'Channel Registration Services',
            new NullLogger(),
            $applyOutgoingKick,
        );
        $this->chanServBot->onBurstComplete(new ServiceIntroductionRequestedEvent('001'));

        $this->akickActions = new IrcAkickActions($this->chanServBot);
        $this->banActions = new IrcChannelBanActions($this->chanServBot);

        $banMatchQuery = new CoreChannelBanMatchQuery(
            $this->channelRepository,
            $this->userRepository,
            $this->connectionHolder,
        );
        $this->banNetworkQuery = new IrcChannelBanNetworkQuery($banMatchQuery);

        $burstComplete = $this->createStub(BurstCompletePort::class);
        $burstComplete->method('isComplete')->willReturn(true);

        $this->entryNetworkQuery = new IrcChannelEntryNetworkQuery(
            $this->channelLookup,
            $this->userLookup,
            'ChanServ',
            $burstComplete,
        );
    }

    /**
     * @return list<array{sid: string, channel: string, modeStr: string, params: array<int, string>, serviceUid: string}>
     */
    private function getRecordedModeChanges(): array
    {
        return $this->recordedModeChanges;
    }
}
