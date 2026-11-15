<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Persistence;

use App\ChanServ\Adapter\Out\Network\ChannelRankModeMapper;
use App\ChanServ\Adapter\Out\Network\IrcChannelRankNetworkQuery;
use App\ChanServ\Adapter\Out\NickServ\NickServChanUserAccountAdapter;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\BurstChannelActivityWriter;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\BurstChannelPageSource;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAccessDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAkickDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelLevelDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\DoctrineChannelRankPolicyRepository;
use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Model\ChannelEntryNetworkState;
use App\ChanServ\Application\Model\ChannelMlockPolicy;
use App\ChanServ\Application\Model\MemberRankChange;
use App\ChanServ\Application\Port\In\ChannelRegistrationLifecycle;
use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;
use App\ChanServ\Application\Port\Out\ChannelRankActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\NojoinActions;
use App\ChanServ\Application\UseCase\ApplyStoredTopic\ApplyStoredChannelTopicHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandler;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoinHandler;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandler;
use App\ChanServ\Application\UseCase\SynchronizeBurst\SynchronizeBurstChannelsHandler;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\AutomaticRankPolicy;
use App\ChanServ\Domain\Policy\ChannelAccessPolicy;
use App\ChanServ\Domain\Policy\RankReconciliationPolicy;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\ChanServ\Domain\ValueObject\RankChangeAction;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\Out\Persistence\Doctrine\NickAccountBatchDoctrineQuery;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
use ArrayObject;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function count;
use function str_starts_with;
use function substr;
use function sys_get_temp_dir;

#[CoversNothing]
final class BurstBatchQueryCountTest extends TestCase
{
    #[Test]
    public function lookupCountIsIndependentOfChannelsAndMembersWithinOnePageAndAkickUsageIsFlushedOncePerChannel(): void
    {
        $small = $this->synchronize(5, 3);
        $large = $this->synchronize(100, 5);
        $twoPages = $this->synchronize(201, 5);
        $threePages = $this->synchronize(401, 5);
        self::assertSame($small['lookups'], $large['lookups']);
        self::assertGreaterThan($large['lookups'], $twoPages['lookups']);
        self::assertSame($twoPages['lookups'] - $large['lookups'], $threePages['lookups'] - $twoPages['lookups']);
        foreach ([[5, $small], [100, $large], [201, $twoPages], [401, $threePages]] as [$channels, $result]) {
            self::assertSame($channels, $result['akickUsageWrites'], 'One AKICK rule matching two members is written once per channel.');
            self::assertSame($channels, $result['akicksUsedTwice']);
        }
    }

    /**
     * Runs the real AKICK, NOJOIN and rank steps of one end-of-burst pass. Per channel, members 1
     * and 2 match one AKICK host rule, member 3 has AUTOOP access and every other member is below
     * NOJOIN.
     *
     * @return array{lookups: int, akickUsageWrites: int, akicksUsedTwice: int}
     */
    private function synchronize(int $channelCount, int $membersPerChannel): array
    {
        $config = new Configuration();
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('BurstQueryProxies');
        $config->setMetadataDriverImpl(new SimplifiedXmlDriver([
            __DIR__ . '/../../../config/doctrine/nickserv' => 'App\\NickServ\\Domain\\Entity',
            __DIR__ . '/../../../config/doctrine/chanserv' => 'App\\ChanServ\\Domain\\Entity',
        ]));
        $logger = new QueryCaptureLogger();
        $config->setMiddlewares([new Middleware($logger)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $em = new EntityManager($connection, $config);
        new SchemaTool($em)->createSchema($em->getMetadataFactory()->getAllMetadata());
        $createdAt = new DateTimeImmutable('2026-01-01 00:00:00 UTC');
        for ($i = 1; $i <= $channelCount; ++$i) {
            $connection->insert('registered_channels', ['id' => $i, 'name' => '#c' . $i, 'name_lower' => '#c' . $i, 'founder_nick_id' => 99999, 'description' => 'Fixture', 'entrymsg' => '', 'topic_lock' => 0, 'mlock_active' => 0, 'mlock' => '', 'mlock_params' => '{}', 'secure' => 0, 'created_at' => '2026-01-01 00:00:00', 'status' => 'active']);
            $connection->insert('channel_levels', ['channel_id' => $i, 'level_key' => 'NOJOIN', 'value' => 1]);
            for ($j = 1; $j <= $membersPerChannel; ++$j) {
                $name = 'n' . $i . '_' . $j;
                $connection->insert('registered_nicks', ['id' => $i * 100 + $j, 'nickname' => $name, 'nickname_lower' => $name, 'status' => 'registered', 'language' => 'en', 'private' => 0, 'msg_privmsg' => 0]);
            }
            $connection->insert('channel_access', ['channel_id' => $i, 'nick_id' => $i * 100 + 3, 'level' => 300]);
            $em->persist(ChannelAkick::create($createdAt, $i, 1, '*!*@bad.host', 'Burst fixture'));
        }
        $em->flush();
        $em->clear();

        /** @var ArrayObject<string, true> $removed */
        $removed = new ArrayObject();
        $liveMembers = static function (string $channelName) use ($membersPerChannel, $removed): array {
            $number = substr($channelName, 2);
            $members = [];
            for ($j = 1; $j <= $membersPerChannel; ++$j) {
                $name = 'n' . $number . '_' . $j;
                if (!isset($removed[$channelName . ' ' . $name])) {
                    $members[] = $name;
                }
            }

            return $members;
        };
        $host = static fn (string $nick): string => '_1' === substr($nick, -2) || '_2' === substr($nick, -2) ? 'bad.host' : 'host';
        $channels = new RegisteredChannelDoctrineRepository($em, NegotiatedCaseMapping::network());
        $akickRepo = new ChannelAkickDoctrineRepository($em);
        $source = new BurstChannelPageSource($channels, $akickRepo, $em);
        $accounts = new NickServChanUserAccountAdapter($this->createStub(NickAccountQuery::class), new NickAccountBatchDoctrineQuery($em, NegotiatedCaseMapping::network()));
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findChannel')->willReturnCallback(static fn (string $channelName): ChannelEntryNetworkState => new ChannelEntryNetworkState(
            $channelName,
            array_map(static fn (string $name): ChannelEntryMember => new ChannelEntryMember($name, $name, $name . '!i@' . $host($name), true, false, false), $liveMembers($channelName)),
        ));
        $akickActions = $this->createMock(AkickActions::class);
        $akickActions->expects(self::exactly($channelCount * 2))->method('banAndKick')
            ->willReturnCallback(static function (string $channelName, string $uid, string $mask) use ($removed): void {
                self::assertSame('*!*@bad.host', $mask);
                $removed[$channelName . ' ' . $uid] = true;
            });
        $akicks = new EnforceChannelAkickHandler($channels, $akickRepo, $network, $akickActions, $this->createStub(ChanServActivitySink::class), NegotiatedCaseMapping::chanServ());
        $nojoinActions = $this->createMock(NojoinActions::class);
        $nojoinActions->expects(self::exactly($channelCount * ($membersPerChannel - 3)))->method('kick')
            ->willReturnCallback(static function (string $channelName, string $uid) use ($removed): void {
                self::assertNotContains(substr($uid, -2), ['_1', '_2', '_3']);
                $removed[$channelName . ' ' . $uid] = true;
            });
        $nojoin = new EnforceChannelNojoinHandler($channels, $this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAccessRepositoryInterface::class), $accounts, $network, $nojoinActions, new ChannelAccessPolicy());
        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturnCallback(static function (string $channelName) use ($liveMembers): ChannelView {
            $members = array_map(static fn (string $uid): array => ['uid' => $uid, 'roleLetter' => '', 'prefixLetters' => []], $liveMembers($channelName));

            return new ChannelView($channelName, '', null, count($members), $members);
        });
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('findByUid')->willReturnCallback(static fn (string $uid): SenderView => new SenderView($uid, $uid, 'i', $host($uid), $host($uid), '', true));
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getSupportedPrefixModes')->willReturn(['o', 'v']);
        $supportProvider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $supportProvider->method('getSupport')->willReturn($support);
        $rankNetwork = new IrcChannelRankNetworkQuery($lookup, $users, $this->createStub(NickAccountQuery::class), $supportProvider, new ChannelRankModeMapper(), 'ChanServ');
        $rankActions = $this->createMock(ChannelRankActions::class);
        $rankActions->expects(self::exactly($channelCount))->method('apply')
            ->willReturnCallback(static function (string $channelName, array $changes): void {
                $applied = [];
                foreach ($changes as $change) {
                    self::assertInstanceOf(MemberRankChange::class, $change);
                    $applied[] = [$change->uid, $change->change->rank, $change->change->action];
                }
                self::assertSame([['n' . substr($channelName, 2) . '_3', ChannelRank::Operator, RankChangeAction::Grant]], $applied);
            });
        $rankPolicies = new DoctrineChannelRankPolicyRepository($channels, new ChannelAccessDoctrineRepository($em), new ChannelLevelDoctrineRepository($em), $em);
        $ranks = new EnforceChannelRanksHandler($rankPolicies, $rankNetwork, $rankActions, new ChannelAccessPolicy(), new AutomaticRankPolicy(), new RankReconciliationPolicy());
        $mlockPolicies = $this->createStub(ChannelMlockPolicyRepository::class);
        $mlockPolicies->method('forChannel')->willReturnCallback(static fn (RegisteredChannel $channel): ChannelMlockPolicy => new ChannelMlockPolicy($channel->getName(), false, ChannelModeLock::inactive()));
        $scope = new BurstRankEventScope(NegotiatedCaseMapping::chanServ());
        $scope->begin();
        $handler = new SynchronizeBurstChannelsHandler($source, $network, $accounts, $this->createStub(ForbiddenChannelEnforcement::class), $akicks, $nojoin, $rankNetwork, $ranks, $mlockPolicies, $this->createStub(EnforceChannelMlockHandlerInterface::class), $this->createStub(ApplyStoredChannelTopicHandlerInterface::class), $this->createStub(ChannelRegistrationLifecycle::class), $scope, new BurstChannelActivityWriter($em));
        $logger->enabled = true;
        $handler->handle(new DateTimeImmutable('2026-02-01 UTC'));
        self::assertSame([], $em->getUnitOfWork()->getIdentityMap()[RegisteredNick::class] ?? []);
        foreach ($em->getUnitOfWork()->getIdentityMap() as $entities) {
            self::assertSame([], $entities);
        }
        $logger->enabled = false;
        $akickUsageWrites = count(array_filter($logger->queries, static fn (array $query): bool => str_starts_with($query['sql'], 'UPDATE channel_akick ')));
        $akicksUsedTwice = $connection->fetchOne('SELECT COUNT(*) FROM channel_akick WHERE uses = 2');
        self::assertIsInt($akicksUsedTwice);
        $lookups = count($logger->queries) - $akickUsageWrites;
        $em->close();
        $connection->close();

        return ['lookups' => $lookups, 'akickUsageWrites' => $akickUsageWrites, 'akicksUsedTwice' => $akicksUsedTwice];
    }
}
