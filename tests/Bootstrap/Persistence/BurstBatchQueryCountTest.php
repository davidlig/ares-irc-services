<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Persistence;

use App\ChanServ\Adapter\Out\NickServ\NickServChanUserAccountAdapter;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\BurstChannelActivityWriter;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\BurstChannelPageSource;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAkickDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\Model\ChannelEntryMember;
use App\ChanServ\Application\Model\ChannelEntryNetworkState;
use App\ChanServ\Application\Model\ChannelMlockPolicy;
use App\ChanServ\Application\Model\ChannelRankNetworkState;
use App\ChanServ\Application\Port\In\ChannelRegistrationLifecycle;
use App\ChanServ\Application\Port\In\ForbiddenChannelEnforcement;
use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelEntryNetworkQuery;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelMlockPolicyRepository;
use App\ChanServ\Application\Port\Out\ChannelRankNetworkQuery;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\NojoinActions;
use App\ChanServ\Application\UseCase\ApplyStoredTopic\ApplyStoredChannelTopicHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandler;
use App\ChanServ\Application\UseCase\EnforceMlock\EnforceChannelMlockHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoinHandler;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementOutcome;
use App\ChanServ\Application\UseCase\EnforceRanks\RankEnforcementResult;
use App\ChanServ\Application\UseCase\SynchronizeBurst\SynchronizeBurstChannelsHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\ChanServ\Domain\Policy\ChannelAccessPolicy;
use App\ChanServ\Domain\ValueObject\ChannelModeLock;
use App\NickServ\Adapter\Out\Persistence\Doctrine\NickAccountBatchDoctrineQuery;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\NickServ\Domain\Entity\RegisteredNick;
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

use function count;
use function substr;
use function sys_get_temp_dir;

#[CoversNothing]
final class BurstBatchQueryCountTest extends TestCase
{
    #[Test]
    public function lookupCountIsIndependentOfChannelsAndMembersWithinOnePage(): void
    {
        $small = $this->queryCount(5, 1);
        $large = $this->queryCount(100, 5);
        $twoPages = $this->queryCount(201, 5);
        $threePages = $this->queryCount(401, 5);
        self::assertSame($small, $large);
        self::assertGreaterThan($large, $twoPages);
        self::assertSame($twoPages - $large, $threePages - $twoPages);
    }

    private function queryCount(int $channelCount, int $membersPerChannel): int
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
        for ($i = 1; $i <= $channelCount; ++$i) {
            $connection->insert('registered_channels', ['id' => $i, 'name' => '#c' . $i, 'name_lower' => '#c' . $i, 'founder_nick_id' => 99999, 'description' => 'Fixture', 'entrymsg' => '', 'topic_lock' => 0, 'mlock_active' => 0, 'mlock' => '', 'mlock_params' => '{}', 'secure' => 0, 'created_at' => '2026-01-01 00:00:00', 'status' => 'active']);
            $connection->insert('channel_levels', ['channel_id' => $i, 'level_key' => 'NOJOIN', 'value' => 1]);
            for ($j = 1; $j <= $membersPerChannel; ++$j) {
                $name = 'n' . $i . '_' . $j;
                $connection->insert('registered_nicks', ['nickname' => $name, 'nickname_lower' => $name, 'status' => 'registered', 'language' => 'en', 'private' => 0, 'msg_privmsg' => 0]);
            }
        }
        $channels = new RegisteredChannelDoctrineRepository($em);
        $akickRepo = new ChannelAkickDoctrineRepository($em);
        $source = new BurstChannelPageSource($channels, $akickRepo, $em);
        $accounts = new NickServChanUserAccountAdapter($this->createStub(NickAccountQuery::class), new NickAccountBatchDoctrineQuery($em));
        $network = $this->createStub(ChannelEntryNetworkQuery::class);
        $network->method('findChannel')->willReturnCallback(static function (string $channelName) use ($membersPerChannel): ChannelEntryNetworkState {
            $number = substr($channelName, 2);
            $members = [];
            for ($j = 1; $j <= $membersPerChannel; ++$j) {
                $name = 'n' . $number . '_' . $j;
                $members[] = new ChannelEntryMember($name, $name, $name . '!i@host', true, false, false);
            }

            return new ChannelEntryNetworkState($channelName, $members);
        });
        $akicks = new EnforceChannelAkickHandler($channels, $akickRepo, $network, $this->createStub(AkickActions::class), new AkickMatchPolicy(), $this->createStub(ChanServActivitySink::class));
        $nojoinActions = $this->createMock(NojoinActions::class);
        $nojoinActions->expects(self::exactly($channelCount * $membersPerChannel))->method('kick');
        $nojoin = new EnforceChannelNojoinHandler($channels, $this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAccessRepositoryInterface::class), $accounts, $network, $nojoinActions, new ChannelAccessPolicy());
        $rankNetwork = $this->createStub(ChannelRankNetworkQuery::class);
        $rankNetwork->method('findChannelWithAccounts')->willReturnCallback(static fn (string $name): ChannelRankNetworkState => new ChannelRankNetworkState($name, [], []));
        $ranks = $this->createStub(EnforceChannelRanksHandlerInterface::class);
        $ranks->method('handleKnownPolicyAndNetwork')->willReturn(new RankEnforcementResult(RankEnforcementOutcome::NoChanges));
        $mlockPolicies = $this->createStub(ChannelMlockPolicyRepository::class);
        $mlockPolicies->method('forChannel')->willReturnCallback(static fn (RegisteredChannel $channel): ChannelMlockPolicy => new ChannelMlockPolicy($channel->getName(), false, ChannelModeLock::inactive()));
        $scope = new BurstRankEventScope();
        $scope->begin();
        $handler = new SynchronizeBurstChannelsHandler($source, $network, $accounts, $this->createStub(ForbiddenChannelEnforcement::class), $akicks, $nojoin, $rankNetwork, $ranks, $mlockPolicies, $this->createStub(EnforceChannelMlockHandlerInterface::class), $this->createStub(ApplyStoredChannelTopicHandlerInterface::class), $this->createStub(ChannelRegistrationLifecycle::class), $scope, new BurstChannelActivityWriter($em));
        $logger->enabled = true;
        $handler->handle(new DateTimeImmutable('2026-02-01 UTC'));
        self::assertSame([], $em->getUnitOfWork()->getIdentityMap()[RegisteredNick::class] ?? []);
        foreach ($em->getUnitOfWork()->getIdentityMap() as $entities) {
            self::assertSame([], $entities);
        }
        $logger->enabled = false;
        $count = count($logger->queries);
        $em->close();
        $connection->close();

        return $count;
    }
}
