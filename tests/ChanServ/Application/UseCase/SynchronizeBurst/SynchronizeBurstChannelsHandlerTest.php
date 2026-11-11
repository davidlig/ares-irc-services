<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\SynchronizeBurst;

use App\ChanServ\Adapter\In\Event\ChanServChannelRankSubscriber;
use App\ChanServ\Adapter\In\Event\PendingChannelRankSynchronizations;
use App\ChanServ\Application\Model\BurstChannelPage;
use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Model\ChannelEntryNetworkState;
use App\ChanServ\Application\Model\ChannelMember;
use App\ChanServ\Application\Model\ChannelMlockPolicy;
use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Port\In\ChannelRegistrationLifecycle;
use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\BurstChannelActivity;
use App\ChanServ\Application\Port\Out\BurstChannelPages;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;
use App\ChanServ\Application\Port\Out\ChannelRankActions;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelRankPolicyRepository;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\NojoinActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelBurstSnapshot;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\ApplyStoredTopic\ApplyStoredChannelTopicHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandler;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementOutcome;
use App\ChanServ\Application\UseCase\EnforceMlock\MlockEnforcementResult;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoinHandler;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoinHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandler;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementOutcome;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementResult;
use App\ChanServ\Application\UseCase\EnforceRanks\SynchronizeAllChannelRanksHandler;
use App\ChanServ\Application\UseCase\SynchronizeBurst\SynchronizeBurstChannelsHandler;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\ChanServ\Domain\Policy\AutomaticRankPolicy;
use App\ChanServ\Domain\Policy\ChannelAccessPolicy;
use App\ChanServ\Domain\Policy\RankReconciliationPolicy;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\Irc\Application\Port\In\BurstCompletePort;
use App\Irc\Application\PublishedEvent\UserDepartedChannelEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_values;

#[CoversClass(SynchronizeBurstChannelsHandler::class)]
#[CoversClass(EnforceChannelAkickHandler::class)]
#[CoversClass(EnforceChannelNojoinHandler::class)]
final class SynchronizeBurstChannelsHandlerTest extends TestCase
{
    #[Test]
    public function appliesEachPageInAuthorizedOrderUsingBulkData(): void
    {
        $now = new DateTimeImmutable('2026-01-01 UTC');
        $forbidden = $this->createStub(RegisteredChannel::class);
        $forbidden->method('getName')->willReturn('#forbidden');
        $forbidden->method('getId')->willReturn(1);
        $forbidden->method('isForbidden')->willReturn(true);
        $forbidden->method('isBlocked')->willReturn(true);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn('#active');
        $channel->method('getId')->willReturn(2);
        $channel->method('getFounderNickId')->willReturn(10);
        $member = new ChannelEntryMember('U1', 'Alice', 'Alice!i@bad.test', true, false, false);
        $akick = ChannelAkick::create($now, 2, 10, '*!*@bad.test');
        $access = new ChannelAccess(2, 42, 100);
        $level = new ChannelLevel(2, ChannelLevel::KEY_NOJOIN, 200);
        $pages = $this->createStub(BurstChannelPages::class);
        $pages->method('pages')->willReturn([new BurstChannelPage([$forbidden, $channel], [$access], [$level], [$akick])]);
        $entryNetwork = $this->createStub(ChannelEntryNetworkQuery::class);
        $entryNetwork->method('findChannel')->willReturn(new ChannelEntryNetworkState('#active', [$member]));
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $alice = new ChanAccountView(42, 'Alice', 'en');
        $accounts->expects(self::once())->method('findAccountsByNicks')->with(['Alice'])->willReturn(['alice' => $alice]);
        $order = [];
        $forbiddenHandler = $this->createMock(ForbiddenChannelEnforcement::class);
        $forbiddenHandler->expects(self::once())->method('enforceKnownForbiddenChannel')->with('#forbidden')->willReturnCallback(static function () use (&$order): void { $order[] = 'forbidden'; });
        $akicks = $this->createMock(EnforceChannelAkickHandlerInterface::class);
        $akicks->expects(self::once())->method('handleKnownChannelWithAkicks')->with($channel, [$member], $now, [$akick])->willReturnCallback(static function () use (&$order): int {
            $order[] = 'akick';

            return 1;
        });
        $nojoin = $this->createMock(EnforceChannelNojoinHandlerInterface::class);
        $nojoin->expects(self::once())->method('handleKnownChannelWithSnapshot')->with($channel, [$member], 200, ['alice' => $alice], [42 => 100])->willReturnCallback(static function () use (&$order): int {
            $order[] = 'nojoin';

            return 1;
        });
        $rankNetwork = $this->createStub(ChannelRankNetworkQuery::class);
        $rankNetwork->method('findChannelWithAccounts')->willReturn(new ChannelRankNetworkState('#active', [], []));
        $ranks = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $ranks->expects(self::once())->method('handleKnownPolicyAndNetwork')->willReturnCallback(static function () use (&$order): RankEnforcementResult {
            $order[] = 'ranks';

            return new RankEnforcementResult(RankEnforcementOutcome::NoChanges);
        });
        $mlockPolicies = $this->createStub(ChannelMlockPolicyRepository::class);
        $mlockPolicies->method('forChannel')->willReturn(new ChannelMlockPolicy('#active', false, ChannelModeLock::active()));
        $mlock = $this->createMock(EnforceChannelMlockHandlerInterface::class);
        $mlock->expects(self::once())->method('handleKnownPolicy')->willReturnCallback(static function () use (&$order): MlockEnforcementResult {
            $order[] = 'mlock';

            return new MlockEnforcementResult(MlockEnforcementOutcome::NoChanges);
        });
        $topic = $this->createMock(ApplyStoredChannelTopicHandlerInterface::class);
        $topic->expects(self::once())->method('synchronizeKnownChannel')->with($channel)->willReturnCallback(static function () use (&$order): void { $order[] = 'topic'; });
        $scope = new BurstRankEventScope();
        $scope->begin();
        $scope->markActivity('#active');
        $activity = $this->createMock(BurstChannelActivity::class);
        $activity->expects(self::once())->method('touchByIds')->with([2], $now);
        $registration = $this->createMock(ChannelRegistrationLifecycle::class);
        $registration->expects(self::once())->method('reconcileKnownChannels')->with(['#forbidden' => true, '#active' => true], ['#active']);

        new SynchronizeBurstChannelsHandler($pages, $entryNetwork, $accounts, $forbiddenHandler, $akicks, $nojoin, $rankNetwork, $ranks, $mlockPolicies, $mlock, $topic, $registration, $scope, $activity)->handle($now);
        self::assertSame(['forbidden', 'akick', 'nojoin', 'ranks', 'mlock', 'topic'], $order);
    }

    #[Test]
    public function akickRemovesOverlappingMemberBeforeNojoin(): void
    {
        $now = new DateTimeImmutable('2026-01-01 UTC');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn('#active');
        $channel->method('getId')->willReturn(2);
        $channel->method('getFounderNickId')->willReturn(10);
        $first = new ChannelEntryMember('U1', 'Alice', 'Alice!i@bad.test', true, false, false);
        $second = new ChannelEntryMember('U2', 'Bob', 'Bob!i@other.test', true, false, false);
        $members = ['U1' => $first, 'U2' => $second];
        $akick = ChannelAkick::create($now, 2, 10, '*!*@bad.test');
        $pages = $this->createStub(BurstChannelPages::class);
        $pages->method('pages')->willReturn([new BurstChannelPage([$channel], [], [new ChannelLevel(2, ChannelLevel::KEY_NOJOIN, 100)], [$akick])]);
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findChannel')->willReturnCallback(static function () use (&$members): ChannelEntryNetworkState {
            return new ChannelEntryNetworkState('#active', array_values($members));
        });
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountsByNicks')->willReturn(['alice' => new ChanAccountView(10, 'Alice', 'en'), 'bob' => new ChanAccountView(2, 'Bob', 'es')]);
        $rankNetwork = $this->createStub(ChannelRankNetworkQuery::class);
        $rankNetwork->method('findChannelWithAccounts')->willReturnCallback(static function () use (&$members): ChannelRankNetworkState {
            $rankMembers = [];
            foreach ($members as $member) {
                $rankMembers[] = new ChannelMember($member->uid, $member->nickname, true, false, false, 'U1' === $member->uid ? 10 : 2, []);
            }

            return new ChannelRankNetworkState('#active', $rankMembers, [ChannelRank::Operator]);
        });
        $policyRepo = $this->createMock(ChannelRankPolicyRepository::class);
        $policyRepo->expects(self::never())->method('touchLastUsed');
        $policyRepo->expects(self::never())->method('findByNameForNick');
        $rankActions = $this->createMock(ChannelRankActions::class);
        $rankActions->expects(self::never())->method('apply');
        $ranks = new EnforceChannelRanksHandler($policyRepo, $rankNetwork, $rankActions, new ChannelAccessPolicy(), new AutomaticRankPolicy(), new RankReconciliationPolicy());
        $mlockPolicies = $this->createStub(ChannelMlockPolicyRepository::class);
        $mlockPolicies->method('forChannel')->willReturn(new ChannelMlockPolicy('#active', false, ChannelModeLock::inactive()));
        $scope = new BurstRankEventScope();
        $scope->begin();
        $subscriber = new ChanServChannelRankSubscriber($ranks, new SynchronizeAllChannelRanksHandler($policyRepo, $ranks), new PendingChannelRankSynchronizations(), $this->createStub(BurstCompletePort::class), $scope, $this->createStub(RegisteredChannelBurstSnapshot::class));
        $trace = [];
        $akickActions = $this->createMock(AkickActions::class);
        $akickActions->expects(self::once())->method('banAndKick')->with('#active', 'U1', '*!*@bad.test', 'AKICK: *!*@bad.test')->willReturnCallback(static function () use (&$trace, &$members, $subscriber): void {
            $trace[] = 'akick:U1';
            $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('U1', '#active'));
            unset($members['U1']);
        });
        $akickRepository = $this->createMock(ChannelAkickRepositoryInterface::class);
        $akickRepository->expects(self::once())->method('saveAll')->with([$akick]);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $akicks = new EnforceChannelAkickHandler($channels, $akickRepository, $network, $akickActions, new AkickMatchPolicy(), $this->createStub(ChanServActivitySink::class));
        $nojoinActions = $this->createMock(NojoinActions::class);
        $nojoinActions->expects(self::once())->method('kick')->with('#active', 'U2', 'Bob', 0, 100, 'es')->willReturnCallback(static function () use (&$trace, &$members, $subscriber): void {
            $trace[] = 'nojoin:U2';
            $subscriber->onUserLeftChannel(new UserDepartedChannelEvent('U2', '#active'));
            unset($members['U2']);
        });
        $nojoin = new EnforceChannelNojoinHandler($channels, $this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAccessRepositoryInterface::class), $accounts, $network, $nojoinActions, new ChannelAccessPolicy());
        $activity = $this->createMock(BurstChannelActivity::class);
        $activity->expects(self::once())->method('touchByIds')->with([2], $now);
        new SynchronizeBurstChannelsHandler($pages, $network, $accounts, $this->createStub(ForbiddenChannelEnforcement::class), $akicks, $nojoin, $rankNetwork, $ranks, $mlockPolicies, $this->createStub(EnforceChannelMlockHandlerInterface::class), $this->createStub(ApplyStoredChannelTopicHandlerInterface::class), $this->createStub(ChannelRegistrationLifecycle::class), $scope, $activity)->handle($now);
        self::assertSame(['akick:U1', 'nojoin:U2'], $trace);
        self::assertSame([], $members);
        self::assertTrue($scope->needsActivity('#active'));
        self::assertSame(1, $akick->getUses());
        self::assertSame($now, $akick->getLastUsedAt());
    }

    #[Test]
    public function missingLiveChannelSkipsEntryEnforcementButStillRunsRemainingPolicies(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getName')->willReturn('#offline');
        $channel->method('getId')->willReturn(7);
        $channel->method('getFounderNickId')->willReturn(10);
        $pages = $this->createStub(BurstChannelPages::class);
        $pages->method('pages')->willReturn([new BurstChannelPage([$channel], [], [], [])]);
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findChannel')->willReturn(null);
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findAccountsByNicks')->with([])->willReturn([]);
        $akicks = $this->createMock(EnforceChannelAkickHandlerInterface::class);
        $akicks->expects(self::never())->method('handleKnownChannelWithAkicks');
        $nojoin = $this->createMock(EnforceChannelNojoinHandlerInterface::class);
        $nojoin->expects(self::never())->method('handleKnownChannelWithSnapshot');
        $rankNetwork = $this->createStub(ChannelRankNetworkQuery::class);
        $rankNetwork->method('findChannelWithAccounts')->willReturn(null);
        $ranks = $this->createMock(EnforceChannelRanksHandlerInterface::class);
        $ranks->expects(self::once())->method('handleKnownPolicyAndNetwork')->willReturn(new RankEnforcementResult(RankEnforcementOutcome::ChannelUnavailable));
        $mlockPolicies = $this->createStub(ChannelMlockPolicyRepository::class);
        $mlockPolicies->method('forChannel')->willReturn(new ChannelMlockPolicy('#offline', false, ChannelModeLock::inactive()));
        $mlock = $this->createMock(EnforceChannelMlockHandlerInterface::class);
        $mlock->expects(self::never())->method('handleKnownPolicy');
        $topic = $this->createMock(ApplyStoredChannelTopicHandlerInterface::class);
        $topic->expects(self::once())->method('synchronizeKnownChannel')->with($channel);
        $activity = $this->createMock(BurstChannelActivity::class);
        $activity->expects(self::once())->method('touchByIds')->with([], self::isInstanceOf(DateTimeImmutable::class));
        new SynchronizeBurstChannelsHandler($pages, $network, $accounts, $this->createStub(ForbiddenChannelEnforcement::class), $akicks, $nojoin, $rankNetwork, $ranks, $mlockPolicies, $mlock, $topic, $this->createStub(ChannelRegistrationLifecycle::class), new BurstRankEventScope(), $activity)->handle(new DateTimeImmutable());
    }
}
