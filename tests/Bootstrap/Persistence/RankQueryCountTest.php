<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Persistence;

use App\ChanServ\Adapter\In\Event\ChanServChannelRankSubscriber;
use App\ChanServ\Adapter\In\Event\PendingChannelRankSynchronizations;
use App\ChanServ\Adapter\Out\Network\ChannelRankModeMapper;
use App\ChanServ\Adapter\Out\Network\IrcChannelRankNetworkQuery;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAccessDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelLevelDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\DoctrineChannelRankPolicyRepository;
use App\ChanServ\Application\Port\Out\ChannelRankActions;
use App\ChanServ\Application\UseCase\EnforceRanks\EnforceChannelRanksHandler;
use App\ChanServ\Application\UseCase\EnforceRanks\SynchronizeAllChannelRanksHandler;
use App\ChanServ\Domain\Policy\AutomaticRankPolicy;
use App\ChanServ\Domain\Policy\ChannelAccessPolicy;
use App\ChanServ\Domain\Policy\RankReconciliationPolicy;
use App\Irc\Adapter\In\Event\PublishedIrcEventBridge;
use App\Irc\Adapter\Network\Event\ChannelJoinReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelKickReceivedEvent;
use App\Irc\Adapter\Network\Event\ChannelPartReceivedEvent;
use App\Irc\Adapter\Network\NetworkEventEnricher;
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
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\Repository\ChannelRepositoryInterface;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Adapter\Out\User\NickAccountResolver;
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

use function count;
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
        $policy = new DoctrineChannelRankPolicyRepository(new RegisteredChannelDoctrineRepository($em), new ChannelAccessDoctrineRepository($em), new ChannelLevelDoctrineRepository($em), $em);
        $users = $this->createStub(NetworkUserLookupPort::class);
        $users->method('findByUid')->willReturnCallback(static fn (string $uid): SenderView => new SenderView($uid, 'N' . substr($uid, 1), 'a', 'host', 'cloak', '', true));
        $support = $this->createStub(ChannelModeSupportInterface::class);
        $support->method('getSupportedPrefixModes')->willReturn(['q', 'a', 'o', 'h', 'v']);
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn($support);
        $counts = [];
        foreach ([2, 80] as $size) {
            $members = [];
            for ($i = 1; $i <= $size; ++$i) {
                $members[] = ['uid' => 'U' . $i, 'roleLetter' => '', 'prefixLetters' => []];
            }
            $channels = $this->createStub(ChannelLookupPort::class);
            $channels->method('findByChannelName')->willReturn(new ChannelView('#test', '', null, $size, $members));
            $channels->method('findMember')->willReturn(new ChannelView('#test', '', null, $size, [$members[1]]));
            $network = new IrcChannelRankNetworkQuery($channels, $users, new NickAccountResolver(new RegisteredNickDoctrineRepository($em), 'en'), $provider, new ChannelRankModeMapper(), 'ChanServ');
            $handler = new EnforceChannelRanksHandler($policy, $network, $this->createStub(ChannelRankActions::class), new ChannelAccessPolicy(), new AutomaticRankPolicy(), new RankReconciliationPolicy());
            $dispatcher = new EventDispatcher();
            $dispatcher->addSubscriber(new PublishedIrcEventBridge($dispatcher));
            $burst = $this->createStub(BurstCompletePort::class);
            $burst->method('isComplete')->willReturn(true);
            $dispatcher->addSubscriber(new ChanServChannelRankSubscriber($handler, new SynchronizeAllChannelRanksHandler($policy, $handler), new PendingChannelRankSynchronizations(), $burst));
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
            }
        }
        foreach ($counts as $queries) {
            self::assertSame($queries[0], $queries[1]);
            self::assertGreaterThan(0, $queries[0]);
        }
        $em->close();
        $connection->close();
    }

    private function queryCount(QueryCaptureLogger $logger): int
    {
        return count($logger->queries);
    }
}
