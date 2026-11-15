<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Persistence;

use App\ChanServ\Adapter\In\Event\ChanServAkickEnforceSubscriber;
use App\ChanServ\Adapter\In\Event\ChanServChannelRankSubscriber;
use App\ChanServ\Adapter\In\Event\ChanServNojoinEnforceSubscriber;
use App\ChanServ\Adapter\In\Event\DelinkDepartureRankScope;
use App\ChanServ\Adapter\In\Event\PendingChannelRankSynchronizations;
use App\ChanServ\Adapter\Out\Network\ChannelRankModeMapper;
use App\ChanServ\Adapter\Out\Network\IrcChannelEntryNetworkQuery;
use App\ChanServ\Adapter\Out\Network\IrcChannelRankNetworkQuery;
use App\ChanServ\Adapter\Out\NickServ\NickServChanUserAccountAdapter;
use App\ChanServ\Adapter\Out\Persistence\ChannelActivityBuffer;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAccessDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAkickDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelLevelDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\DoctrineChannelRankPolicyRepository;
use App\ChanServ\Application\Model\BurstRankEventScope;
use App\ChanServ\Application\Port\Out\AkickActions;
use App\ChanServ\Application\Port\Out\ChannelRankActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\NojoinActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelBurstSnapshot;
use App\ChanServ\Application\UseCase\EnforceAkick\EnforceChannelAkickHandler;
use App\ChanServ\Application\UseCase\EnforceAkick\SynchronizeAllChannelAkicksHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceNojoin\EnforceChannelNojoinHandler;
use App\ChanServ\Application\UseCase\EnforceNojoin\SynchronizeAllChannelNojoinHandlerInterface;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandler;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Policy\AkickMatchPolicy;
use App\ChanServ\Domain\Policy\AutomaticRankPolicy;
use App\ChanServ\Domain\Policy\ChannelAccessPolicy;
use App\ChanServ\Domain\Policy\RankReconciliationPolicy;
use App\Irc\Adapter\In\Event\PublishedIrcEventBridge;
use App\Irc\Adapter\Network\ChannelSyncCompletedRegistry;
use App\Irc\Adapter\Network\Event\ChannelJoinReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelKickReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelPartReceivedEvent;
use App\Irc\Adapter\Network\Event\UserQuitReceivedEvent;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Network\NetworkStateSubscriber;
use App\Irc\Adapter\Network\ServerDelinkedSubscriber;
use App\Irc\Adapter\ServiceBridge\CoreChannelLookupAdapter;
use App\Irc\Adapter\ServiceBridge\CoreNetworkUserLookupAdapter;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\BurstCompletePort;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Application\PublishedEvent\ChannelMemberRankGrantedEvent;
use App\Irc\Application\PublishedEvent\ChannelSynchronizedEvent;
use App\Irc\Domain\Event\ServerDelinkedEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\Repository\ChannelRepositoryInterface;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use App\NickServ\Adapter\Out\Persistence\Doctrine\NickAccountBatchDoctrineQuery;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Adapter\Out\User\NickAccountResolver;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
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
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_filter;
use function array_map;
use function count;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr;
use function sys_get_temp_dir;

#[CoversNothing]
final class RankQueryCountTest extends TestCase
{
    #[Test]
    public function singleMemberEnforcementQueriesStayConstantAsChannelGrows(): void
    {
        $config = new Configuration();
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('RankQueryProxies');
        $config->setMetadataDriverImpl(new SimplifiedXmlDriver([
            __DIR__ . '/../../../config/doctrine/nickserv' => 'App\\NickServ\\Domain\\Entity',
            __DIR__ . '/../../../config/doctrine/chanserv' => 'App\\ChanServ\\Domain\\Entity',
        ]));
        $logger = new QueryCaptureLogger();
        $config->setMiddlewares([new Middleware($logger)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $em = new EntityManager($connection, $config);
        new SchemaTool($em)->createSchema($em->getMetadataFactory()->getAllMetadata());
        $connection->insert('registered_channels', ['id' => 1, 'name' => '#test', 'name_lower' => '#test', 'founder_nick_id' => 1, 'description' => 'Fixture', 'entrymsg' => '', 'topic_lock' => 0, 'mlock_active' => 0, 'mlock' => '', 'mlock_params' => '{}', 'secure' => 1, 'created_at' => '2026-09-27 00:00:00', 'status' => 'active']);
        for ($i = 1; $i <= 80; ++$i) {
            $connection->insert('registered_nicks', ['id' => $i, 'nickname' => 'N' . $i, 'nickname_lower' => 'n' . $i, 'status' => 'registered', 'language' => 'en', 'private' => 0, 'msg_privmsg' => 0]);
            $connection->insert('channel_access', ['channel_id' => 1, 'nick_id' => $i, 'level' => 300]);
        }
        $connection->insert('channel_levels', ['channel_id' => 1, 'level_key' => 'NOJOIN', 'value' => 1]);
        $akick = ChannelAkick::create(new DateTimeImmutable('2026-09-27 00:00:00'), 1, 1, 'N2!*@*', 'Join fixture');
        $em->persist($akick);
        $em->flush();
        $akickId = $akick->getId();
        $em->clear();
        $accounts = new NickServChanUserAccountAdapter(new NickAccountResolver(new RegisteredNickDoctrineRepository($em, NegotiatedCaseMapping::network()), 'en'), new NickAccountBatchDoctrineQuery($em, NegotiatedCaseMapping::network()));
        $akickActions = $this->createMock(AkickActions::class);
        $akickActions->expects(self::exactly(2))->method('banAndKick')->with('#test', 'U2', 'N2!*@*', 'Join fixture');
        $nojoinActions = $this->createMock(NojoinActions::class);
        $nojoinActions->expects(self::never())->method('kick');
        $policy = new DoctrineChannelRankPolicyRepository(new RegisteredChannelDoctrineRepository($em, NegotiatedCaseMapping::network()), new ChannelAccessDoctrineRepository($em), new ChannelLevelDoctrineRepository($em), $em);
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('findByUid')->willReturnCallback(static fn (string $uid): SenderView => new SenderView($uid, 'N' . substr($uid, 1), 'a', 'host', 'cloak', '', true));
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);
        $counts = [];
        $joinQueries = [];
        foreach ([2, 80] as $size) {
            $members = [];
            for ($i = 1; $i <= $size; ++$i) {
                $members[] = ['uid' => 'U' . $i, 'roleLetter' => '', 'prefixLetters' => []];
            }
            $channels = $this->createStub(ChannelLookupPort::class);
            $channels->method('findByChannelName')->willReturn(new ChannelView('#test', '', null, $size, $members));
            $channels->method('findMember')->willReturn(new ChannelView('#test', '', null, $size, [$members[1]]));
            $network = new IrcChannelRankNetworkQuery($channels, $users, new NickAccountResolver(new RegisteredNickDoctrineRepository($em, NegotiatedCaseMapping::network()), 'en'), $provider, new ChannelRankModeMapper(), 'ChanServ');
            $handler = new EnforceChannelRanksHandler($policy, $network, $this->createStub(ChannelRankActions::class), new ChannelAccessPolicy(), new AutomaticRankPolicy(), new RankReconciliationPolicy());
            $dispatcher = new EventDispatcher();
            $dispatcher->addSubscriber(new PublishedIrcEventBridge($dispatcher));
            $burst = $this->createStub(BurstCompletePort::class);
            $burst->method('isComplete')->willReturn(true);
            $entryNetwork = new IrcChannelEntryNetworkQuery($channels, $users, 'ChanServ', $burst);
            $registeredChannels = new RegisteredChannelDoctrineRepository($em, NegotiatedCaseMapping::network());
            $dispatcher->addSubscriber(new ChanServNojoinEnforceSubscriber(
                new EnforceChannelNojoinHandler($registeredChannels, new ChannelLevelDoctrineRepository($em), new ChannelAccessDoctrineRepository($em), $accounts, $entryNetwork, $nojoinActions, new ChannelAccessPolicy()),
                $this->createStub(SynchronizeAllChannelNojoinHandlerInterface::class),
            ));
            $dispatcher->addSubscriber(new ChanServAkickEnforceSubscriber(
                new EnforceChannelAkickHandler($registeredChannels, new ChannelAkickDoctrineRepository($em), $entryNetwork, $akickActions, new AkickMatchPolicy(), $this->createStub(ChanServActivitySink::class), NegotiatedCaseMapping::chanServ()),
                $this->createStub(SynchronizeAllChannelAkicksHandlerInterface::class),
            ));
            $dispatcher->addSubscriber(new ChanServChannelRankSubscriber($handler, new PendingChannelRankSynchronizations(), $burst, new BurstRankEventScope(NegotiatedCaseMapping::chanServ()), $this->createStub(RegisteredChannelBurstSnapshot::class), new DelinkDepartureRankScope($policy, $network)));
            $channelName = new ChannelName('#test');
            $channel = new Channel($channelName, '', new DateTimeImmutable('@100'));
            $channel->syncMember(new Uid('U1'), ChannelMemberRole::None);
            $channelRepo = $this->createStub(ChannelRepositoryInterface::class);
            $channelRepo->method('findByName')->willReturn($channel);
            $user = new NetworkUser(new Uid('U2'), new Nick('N2'), new Ident('ident'), 'host', 'cloak', 'host', '', new DateTimeImmutable('@100'), 'user', '001', '');
            $userRepo = $this->createStub(NetworkUserRepositoryInterface::class);
            $userRepo->method('findByUid')->willReturn($user);
            $userRepo->method('findByNick')->willReturn($user);
            $enricher = new NetworkEventEnricher($channelRepo, $userRepo, $dispatcher, $this->createStub(SkipIdentifiedModeStripRegistry::class), $provider, $this->createStub(ActiveProtocolModuleHolderInterface::class));
            $events = [
                'join' => static fn () => $enricher->onChannelJoinReceived(new ChannelJoinReceivedEvent($channelName, 100, '', [['uid' => new Uid('U2'), 'role' => ChannelMemberRole::None]])),
                'part' => static fn () => $enricher->onChannelPartReceived(new ChannelPartReceivedEvent('U2', $channelName, '')),
                'kick' => static fn () => $enricher->onChannelKickReceived(new ChannelKickReceivedEvent($channelName, 'U2', '')),
                'grant' => static fn () => $dispatcher->dispatch(new ChannelMemberRankGrantedEvent('#test', 'U2', 'op')),
                'sync' => static fn () => $dispatcher->dispatch(new ChannelSynchronizedEvent('#test')),
            ];
            foreach ($events as $name => $dispatch) {
                $em->clear();
                $logger->queries = [];
                $logger->enabled = true;
                $dispatch();
                $logger->enabled = false;
                $counts[$name][] = $this->queryCount($logger);
                if ('join' === $name) {
                    $joinQueries[] = $logger->queries;
                }
            }
        }
        foreach ($counts as $queries) {
            self::assertSame($queries[0], $queries[1]);
            self::assertGreaterThan(0, $queries[0]);
        }
        foreach ($joinQueries as $queries) {
            self::assertSame(1, $this->countMatching($queries, static fn (string $sql): bool => str_contains($sql, 'FROM channel_akick ')), 'AKICK rules are read once per JOIN.');
            self::assertSame(1, $this->countMatching($queries, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE channel_akick ')), 'AKICK usage is flushed once per JOIN.');
            self::assertSame(1, $this->countMatching($queries, static fn (string $sql): bool => str_contains($sql, 'FROM channel_levels') && str_contains($sql, 'level_key = ?')), 'NOJOIN reads its level once per JOIN.');
            self::assertGreaterThan(0, $this->countMatching($queries, static fn (string $sql): bool => str_contains($sql, 'FROM channel_access')), 'NOJOIN and rank access lookups run on JOIN.');
        }
        self::assertSame(2, $connection->fetchOne('SELECT uses FROM channel_akick WHERE id = ?', [$akickId]));
        $em->close();
        $connection->close();
    }

    #[Test]
    public function serverDelinkDepartureRankWorkFollowsTheDeparturesNotTheChannelSize(): void
    {
        $config = new Configuration();
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('RankQueryProxies');
        $config->setMetadataDriverImpl(new SimplifiedXmlDriver([
            __DIR__ . '/../../../config/doctrine/nickserv' => 'App\\NickServ\\Domain\\Entity',
            __DIR__ . '/../../../config/doctrine/chanserv' => 'App\\ChanServ\\Domain\\Entity',
        ]));
        $logger = new QueryCaptureLogger();
        $config->setMiddlewares([new Middleware($logger)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $em = new EntityManager($connection, $config);
        new SchemaTool($em)->createSchema($em->getMetadataFactory()->getAllMetadata());
        $connection->insert('registered_channels', ['id' => 1, 'name' => '#test', 'name_lower' => '#test', 'founder_nick_id' => 1, 'description' => 'Fixture', 'entrymsg' => '', 'topic_lock' => 0, 'mlock_active' => 0, 'mlock' => '', 'mlock_params' => '{}', 'secure' => 0, 'created_at' => '2026-09-27 00:00:00', 'status' => 'active']);
        for ($i = 1; $i <= 40; ++$i) {
            $connection->insert('registered_nicks', ['id' => $i, 'nickname' => 'N' . $i, 'nickname_lower' => 'n' . $i, 'status' => 'registered', 'language' => 'en', 'private' => 0, 'msg_privmsg' => 0]);
            $connection->insert('channel_access', ['channel_id' => 1, 'nick_id' => $i, 'level' => 300]);
        }
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);

        $results = [];
        foreach ([2, 40] as $size) {
            foreach (['batched', 'unbatched'] as $mode) {
                $em->clear();
                $activity = new ChannelActivityBuffer($connection);
                $policy = new DoctrineChannelRankPolicyRepository(new RegisteredChannelDoctrineRepository($em, NegotiatedCaseMapping::network()), new ChannelAccessDoctrineRepository($em), new ChannelLevelDoctrineRepository($em), $em, $activity);
                $users = new InMemoryNetworkUserRepository();
                $channels = new InMemoryChannelRepository();
                $network = new IrcChannelRankNetworkQuery(new CoreChannelLookupAdapter($channels), new CoreNetworkUserLookupAdapter($users), new NickAccountResolver(new RegisteredNickDoctrineRepository($em, NegotiatedCaseMapping::network()), 'en'), $provider, new ChannelRankModeMapper(), 'ChanServ');
                $rankActions = $this->createMock(ChannelRankActions::class);
                $rankActions->expects(self::never())->method('apply');
                $handler = new EnforceChannelRanksHandler($policy, $network, $rankActions, new ChannelAccessPolicy(), new AutomaticRankPolicy(), new RankReconciliationPolicy());
                $burst = $this->createStub(BurstCompletePort::class);
                $burst->method('isComplete')->willReturn(true);
                $dispatcher = new EventDispatcher();
                $dispatcher->addSubscriber(new PublishedIrcEventBridge($dispatcher));
                $dispatcher->addSubscriber(new ServerDelinkedSubscriber($users, $dispatcher));
                $dispatcher->addSubscriber(new NetworkStateSubscriber($users, $channels, new ChannelSyncCompletedRegistry()));
                $dispatcher->addSubscriber(new NetworkEventEnricher($channels, $users, $dispatcher, $this->createStub(SkipIdentifiedModeStripRegistry::class), $provider, $this->createStub(ActiveProtocolModuleHolderInterface::class)));
                $dispatcher->addSubscriber(new ChanServChannelRankSubscriber($handler, new PendingChannelRankSynchronizations(), $burst, new BurstRankEventScope(NegotiatedCaseMapping::chanServ()), $this->createStub(RegisteredChannelBurstSnapshot::class), new DelinkDepartureRankScope($policy, $network)));

                $channelName = new ChannelName('#test');
                $channel = new Channel($channelName, '+nt', new DateTimeImmutable('@100'));
                $splitUids = [];
                for ($i = 0; $i <= $size; ++$i) {
                    $uid = sprintf('%sAAA%03d', 0 === $i ? '001' : '002', $i);
                    $user = new NetworkUser(new Uid($uid), new Nick(0 === $i ? 'Hub' : 'N' . $i), new Ident('ident'), 'host', 'cloak', 'host', '+r', new DateTimeImmutable('@100'), 'user', 0 === $i ? '001' : '002', '');
                    $user->addChannel($channelName);
                    $users->add($user);
                    $channel->syncMember($user->uid, ChannelMemberRole::None);
                    if (0 !== $i) {
                        $splitUids[] = $uid;
                    }
                }
                for ($i = 1; $i <= 200; ++$i) {
                    $local = new NetworkUser(new Uid(sprintf('001AAB%03d', $i)), new Nick('L' . $i), new Ident('ident'), 'host', 'cloak', 'host', '+r', new DateTimeImmutable('@100'), 'user', '001', '');
                    $local->addChannel($channelName);
                    $users->add($local);
                    $channel->syncMember($local->uid, ChannelMemberRole::None);
                }
                $channels->save($channel);

                $logger->queries = [];
                $logger->enabled = true;
                if ('batched' === $mode) {
                    $dispatcher->dispatch(new ServerDelinkedEvent('002', ''));
                } else {
                    foreach ($splitUids as $uid) {
                        $dispatcher->dispatch(new UserQuitReceivedEvent($uid, '*.net *.split'));
                    }
                }
                $logger->enabled = false;

                $results[$mode][$size] = [
                    'queries' => $this->queryCount($logger),
                    'policyLoads' => $this->countMatching($logger->queries, static fn (string $sql): bool => str_contains($sql, 'FROM registered_channels')),
                    'nickLookups' => $this->boundParameters($logger->queries, static fn (string $sql): bool => str_contains($sql, 'FROM registered_nicks')),
                    'activity' => $activity->hasPendingActivity(1),
                    'members' => array_map(static fn ($member): string => $member->uid->value, $channels->findByName($channelName)?->getMembers() ?? []),
                ];
            }
        }

        self::assertSame(2, $results['batched'][2]['nickLookups'], 'Only departing members are resolved, not the 200 members that stay.');
        self::assertSame(40, $results['batched'][40]['nickLookups'], 'Only departing members are resolved, not the 200 members that stay.');
        self::assertSame(38, $results['batched'][40]['queries'] - $results['batched'][2]['queries'], 'One account lookup per departure and nothing per remaining member.');
        self::assertSame(1, $results['batched'][2]['policyLoads'], 'One rank pass per affected channel.');
        self::assertSame(1, $results['batched'][40]['policyLoads'], 'One rank pass per affected channel.');
        self::assertSame(40, $results['unbatched'][40]['policyLoads'], 'Without the delink scope every departure loads the channel.');
        foreach ([2, 40] as $size) {
            self::assertTrue($results['batched'][$size]['activity']);
            self::assertSame($results['unbatched'][$size]['activity'], $results['batched'][$size]['activity']);
            self::assertCount(201, $results['batched'][$size]['members']);
            self::assertNotContains('002AAA001', $results['batched'][$size]['members']);
            self::assertSame($results['unbatched'][$size]['members'], $results['batched'][$size]['members']);
        }
        $em->close();
        $connection->close();
    }

    /**
     * @param list<array{sql: string, params: list<mixed>, types: list<mixed>}> $queries
     * @param callable(string): bool                                            $predicate
     */
    private function boundParameters(array $queries, callable $predicate): int
    {
        $bound = 0;
        foreach ($queries as $query) {
            if ($predicate($query['sql'])) {
                $bound += count($query['params']);
            }
        }

        return $bound;
    }

    /**
     * @param list<array{sql: string, params: list<mixed>, types: list<mixed>}> $queries
     * @param callable(string): bool                                            $predicate
     */
    private function countMatching(array $queries, callable $predicate): int
    {
        return count(array_filter($queries, static fn (array $query): bool => $predicate($query['sql'])));
    }

    private function queryCount(QueryCaptureLogger $logger): int
    {
        return count($logger->queries);
    }
}
