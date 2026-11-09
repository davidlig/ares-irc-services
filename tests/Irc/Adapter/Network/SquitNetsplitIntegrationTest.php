<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\ChannelSyncCompletedRegistry;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\NetworkStateSubscriber;
use App\Irc\Adapter\Network\ServerDelinkedSubscriber;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNetworkStateAdapter;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNetworkStateAdapter;
use App\Irc\Domain\Event\ServerDelinkedEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(ServerDelinkedSubscriber::class)]
#[CoversClass(InMemoryNetworkUserRepository::class)]
#[CoversClass(InspIRCdNetworkStateAdapter::class)]
#[CoversClass(UnrealStandaloneNetworkStateAdapter::class)]
#[CoversClass(UnrealUdbNetworkStateAdapter::class)]
#[CoversClass(ServerDelinkedEvent::class)]
final class SquitNetsplitIntegrationTest extends TestCase
{
    #[Test]
    public function inspircdSquitRemovesDownstreamUsersBySidAndByName(): void
    {
        $dispatcher = new EventDispatcher();
        $userRepo = new InMemoryNetworkUserRepository();
        $channelRepo = new InMemoryChannelRepository();
        $registry = new ChannelSyncCompletedRegistry();

        $dispatcher->addSubscriber(new ServerDelinkedSubscriber($userRepo, $dispatcher));
        $dispatcher->addSubscriber(new NetworkStateSubscriber($userRepo, $channelRepo, $registry));

        $adapter = new InspIRCdNetworkStateAdapter($dispatcher);

        // Server topology: Hub (0A1) -> Leaf1 (0A2) -> Leaf2 (0A3); Hub (0A1) -> Leaf3 (0A4)
        $adapter->handleMessage(new IRCMessage('SERVER', null, ['hub.example.net', 'pass', '0A1'], 'Hub'));
        $adapter->handleMessage(new IRCMessage('SERVER', '0A1', ['leaf1.example.net', 'pass', '0A2'], 'Leaf 1'));
        $adapter->handleMessage(new IRCMessage('SERVER', '0A2', ['leaf2.example.net', 'pass', '0A3'], 'Leaf 2'));
        $adapter->handleMessage(new IRCMessage('SERVER', '0A1', ['leaf3.example.net', 'pass', '0A4'], 'Leaf 3'));

        // Introduce users
        $adapter->handleMessage(new IRCMessage('UID', '0A1', ['0A1AAAAAA', '1700000000', 'UserA', 'h1', 'c1', 'u1', 'u1', '127.0.0.1', '1700000000', '+i'], 'User A'));
        $adapter->handleMessage(new IRCMessage('UID', '0A2', ['0A2BBBBBB', '1700000000', 'UserB', 'h2', 'c2', 'u2', 'u2', '127.0.0.1', '1700000000', '+i'], 'User B'));
        $adapter->handleMessage(new IRCMessage('UID', '0A3', ['0A3CCCCCC', '1700000000', 'UserC', 'h3', 'c3', 'u3', 'u3', '127.0.0.1', '1700000000', '+i'], 'User C'));
        $adapter->handleMessage(new IRCMessage('UID', '0A4', ['0A4DDDDDD', '1700000000', 'UserD', 'h4', 'c4', 'u4', 'u4', '127.0.0.1', '1700000000', '+i'], 'User D'));

        $chanName = new ChannelName('#test');
        $registry->markSyncCompleted($chanName->value);
        $channel = new Channel($chanName, '+nt', new DateTimeImmutable('@1700000000'));
        $channelRepo->save($channel);

        foreach (['0A1AAAAAA', '0A2BBBBBB', '0A3CCCCCC', '0A4DDDDDD'] as $uidStr) {
            $u = $userRepo->findByUid(new Uid($uidStr));
            self::assertNotNull($u);
            $u->addChannel($chanName);
            $channel->syncMember($u->uid, ChannelMemberRole::None);
        }
        $channelRepo->save($channel);

        self::assertCount(4, $userRepo->all());
        self::assertCount(4, $channel->getMembers());

        // SQUIT Leaf 1 by SID (0A2) -> Should remove UserB (on 0A2) AND UserC (on 0A3)
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['0A2'], 'Split'));

        self::assertNull($userRepo->findByUid(new Uid('0A2BBBBBB')));
        self::assertNull($userRepo->findByUid(new Uid('0A3CCCCCC')));
        self::assertNotNull($userRepo->findByUid(new Uid('0A1AAAAAA')));
        self::assertNotNull($userRepo->findByUid(new Uid('0A4DDDDDD')));
        self::assertCount(2, $userRepo->all());

        self::assertNull($channel->getMember(new Uid('0A2BBBBBB')));
        self::assertNull($channel->getMember(new Uid('0A3CCCCCC')));
        self::assertNotNull($channel->getMember(new Uid('0A1AAAAAA')));
        self::assertNotNull($channel->getMember(new Uid('0A4DDDDDD')));
        self::assertCount(2, $channel->getMembers());

        // Reconnect Leaf 1 and Leaf 2
        $adapter->handleMessage(new IRCMessage('SERVER', '0A1', ['leaf1.example.net', 'pass', '0A2'], 'Leaf 1'));
        $adapter->handleMessage(new IRCMessage('SERVER', '0A2', ['leaf2.example.net', 'pass', '0A3'], 'Leaf 2'));
        $adapter->handleMessage(new IRCMessage('UID', '0A2', ['0A2BBBBBB', '1700000000', 'UserB', 'h2', 'c2', 'u2', 'u2', '127.0.0.1', '1700000000', '+i'], 'User B'));
        $adapter->handleMessage(new IRCMessage('UID', '0A3', ['0A3CCCCCC', '1700000000', 'UserC', 'h3', 'c3', 'u3', 'u3', '127.0.0.1', '1700000000', '+i'], 'User C'));
        foreach (['0A2BBBBBB', '0A3CCCCCC'] as $uidStr) {
            $u = $userRepo->findByUid(new Uid($uidStr));
            self::assertNotNull($u);
            $u->addChannel($chanName);
            $channel->syncMember($u->uid, ChannelMemberRole::None);
        }
        $channelRepo->save($channel);
        self::assertCount(4, $userRepo->all());
        self::assertCount(4, $channel->getMembers());

        // SQUIT Leaf 1 by Name ('leaf1.example.net') -> Should remove UserB and UserC
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['leaf1.example.net'], 'Split by name'));

        self::assertNull($userRepo->findByUid(new Uid('0A2BBBBBB')));
        self::assertNull($userRepo->findByUid(new Uid('0A3CCCCCC')));
        self::assertNotNull($userRepo->findByUid(new Uid('0A1AAAAAA')));
        self::assertNotNull($userRepo->findByUid(new Uid('0A4DDDDDD')));
        self::assertCount(2, $userRepo->all());
        self::assertCount(2, $channel->getMembers());

        // SQUIT Hub by Name ('hub.example.net') -> Direct server split: all remaining users removed
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['hub.example.net'], 'Hub down'));
        self::assertSame([], $userRepo->all());
        self::assertCount(0, $channel->getMembers());
        self::assertNull($channelRepo->findByName($chanName));
        self::assertFalse($registry->isSyncCompleted($chanName->value));
    }

    #[Test]
    public function unrealStandaloneSquitRemovesDownstreamUsersBySidAndByName(): void
    {
        $dispatcher = new EventDispatcher();
        $userRepo = new InMemoryNetworkUserRepository();
        $channelRepo = new InMemoryChannelRepository();
        $registry = new ChannelSyncCompletedRegistry();

        $dispatcher->addSubscriber(new ServerDelinkedSubscriber($userRepo, $dispatcher));
        $dispatcher->addSubscriber(new NetworkStateSubscriber($userRepo, $channelRepo, $registry));

        $adapter = new UnrealStandaloneNetworkStateAdapter($dispatcher);

        // Server topology: Hub (001) -> Leaf1 (002) -> Leaf2 (003); Hub (001) -> Leaf3 (004)
        $adapter->handleMessage(new IRCMessage('PROTOCTL', null, ['NOQUIT', 'SID=001']));
        $adapter->handleMessage(new IRCMessage('SERVER', null, ['hub.example.net', '1'], 'Hub'));
        $adapter->handleMessage(new IRCMessage('SID', '001', ['leaf1.example.net', '1', '002'], 'Leaf 1'));
        $adapter->handleMessage(new IRCMessage('SID', '002', ['leaf2.example.net', '2', '003'], 'Leaf 2'));
        $adapter->handleMessage(new IRCMessage('SID', '001', ['leaf3.example.net', '1', '004'], 'Leaf 3'));

        // Introduce users: Nick hop timestamp user host uid servicestamp umodes virthost cloakedhost ip :gecos
        $adapter->handleMessage(new IRCMessage('UID', '001', ['UserA', '1', '1700000000', 'u1', 'h1', '001AAAAAA', '0', '+i', '*', '*', 'dGVzdA=='], 'User A'));
        $adapter->handleMessage(new IRCMessage('UID', '002', ['UserB', '1', '1700000000', 'u2', 'h2', '002BBBBBB', '0', '+i', '*', '*', 'dGVzdA=='], 'User B'));
        $adapter->handleMessage(new IRCMessage('UID', '003', ['UserC', '1', '1700000000', 'u3', 'h3', '003CCCCCC', '0', '+i', '*', '*', 'dGVzdA=='], 'User C'));
        $adapter->handleMessage(new IRCMessage('UID', '004', ['UserD', '1', '1700000000', 'u4', 'h4', '004DDDDDD', '0', '+i', '*', '*', 'dGVzdA=='], 'User D'));

        $chanName = new ChannelName('#test');
        $registry->markSyncCompleted($chanName->value);
        $channel = new Channel($chanName, '+nt', new DateTimeImmutable('@1700000000'));
        $channelRepo->save($channel);

        foreach (['001AAAAAA', '002BBBBBB', '003CCCCCC', '004DDDDDD'] as $uidStr) {
            $u = $userRepo->findByUid(new Uid($uidStr));
            self::assertNotNull($u);
            $u->addChannel($chanName);
            $channel->syncMember($u->uid, ChannelMemberRole::None);
        }
        $channelRepo->save($channel);

        self::assertCount(4, $userRepo->all());
        self::assertCount(4, $channel->getMembers());

        // SQUIT Leaf 1 by SID (002) -> Should remove UserB (on 002) AND UserC (on 003)
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['002'], 'Split'));

        self::assertNull($userRepo->findByUid(new Uid('002BBBBBB')));
        self::assertNull($userRepo->findByUid(new Uid('003CCCCCC')));
        self::assertNotNull($userRepo->findByUid(new Uid('001AAAAAA')));
        self::assertNotNull($userRepo->findByUid(new Uid('004DDDDDD')));
        self::assertCount(2, $userRepo->all());

        self::assertNull($channel->getMember(new Uid('002BBBBBB')));
        self::assertNull($channel->getMember(new Uid('003CCCCCC')));
        self::assertNotNull($channel->getMember(new Uid('001AAAAAA')));
        self::assertNotNull($channel->getMember(new Uid('004DDDDDD')));
        self::assertCount(2, $channel->getMembers());

        // Reconnect Leaf 1 and Leaf 2
        $adapter->handleMessage(new IRCMessage('SID', '001', ['leaf1.example.net', '1', '002'], 'Leaf 1'));
        $adapter->handleMessage(new IRCMessage('SID', '002', ['leaf2.example.net', '2', '003'], 'Leaf 2'));
        $adapter->handleMessage(new IRCMessage('UID', '002', ['UserB', '1', '1700000000', 'u2', 'h2', '002BBBBBB', '0', '+i', '*', '*', 'dGVzdA=='], 'User B'));
        $adapter->handleMessage(new IRCMessage('UID', '003', ['UserC', '1', '1700000000', 'u3', 'h3', '003CCCCCC', '0', '+i', '*', '*', 'dGVzdA=='], 'User C'));
        foreach (['002BBBBBB', '003CCCCCC'] as $uidStr) {
            $u = $userRepo->findByUid(new Uid($uidStr));
            self::assertNotNull($u);
            $u->addChannel($chanName);
            $channel->syncMember($u->uid, ChannelMemberRole::None);
        }
        $channelRepo->save($channel);
        self::assertCount(4, $userRepo->all());
        self::assertCount(4, $channel->getMembers());

        // SQUIT Leaf 1 by Name ('leaf1.example.net') -> Should remove UserB and UserC
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['leaf1.example.net'], 'Split by name'));

        self::assertNull($userRepo->findByUid(new Uid('002BBBBBB')));
        self::assertNull($userRepo->findByUid(new Uid('003CCCCCC')));
        self::assertNotNull($userRepo->findByUid(new Uid('001AAAAAA')));
        self::assertNotNull($userRepo->findByUid(new Uid('004DDDDDD')));
        self::assertCount(2, $userRepo->all());
        self::assertCount(2, $channel->getMembers());

        // SQUIT Hub by Name ('hub.example.net') -> Direct server split: all remaining users removed
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['hub.example.net'], 'Hub down'));
        self::assertSame([], $userRepo->all());
        self::assertCount(0, $channel->getMembers());
        self::assertNull($channelRepo->findByName($chanName));
        self::assertFalse($registry->isSyncCompleted($chanName->value));
    }

    #[Test]
    public function unrealUdbSquitRemovesDownstreamUsersBySidAndByName(): void
    {
        $dispatcher = new EventDispatcher();
        $userRepo = new InMemoryNetworkUserRepository();
        $channelRepo = new InMemoryChannelRepository();
        $registry = new ChannelSyncCompletedRegistry();

        $dispatcher->addSubscriber(new ServerDelinkedSubscriber($userRepo, $dispatcher));
        $dispatcher->addSubscriber(new NetworkStateSubscriber($userRepo, $channelRepo, $registry));

        $adapter = new UnrealUdbNetworkStateAdapter($dispatcher);

        // Server topology: Hub (001) -> Leaf1 (002) -> Leaf2 (003); Hub (001) -> Leaf3 (004)
        $adapter->handleMessage(new IRCMessage('PROTOCTL', null, ['NOQUIT', 'SID=001']));
        $adapter->handleMessage(new IRCMessage('SERVER', null, ['hub.example.net', '1'], 'Hub'));
        $adapter->handleMessage(new IRCMessage('SID', '001', ['leaf1.example.net', '1', '002'], 'Leaf 1'));
        $adapter->handleMessage(new IRCMessage('SID', '002', ['leaf2.example.net', '2', '003'], 'Leaf 2'));
        $adapter->handleMessage(new IRCMessage('SID', '001', ['leaf3.example.net', '1', '004'], 'Leaf 3'));

        // Introduce users
        $adapter->handleMessage(new IRCMessage('UID', '001', ['UserA', '1', '1700000000', 'u1', 'h1', '001AAAAAA', '0', '+i', '*', '*', 'dGVzdA=='], 'User A'));
        $adapter->handleMessage(new IRCMessage('UID', '002', ['UserB', '1', '1700000000', 'u2', 'h2', '002BBBBBB', '0', '+i', '*', '*', 'dGVzdA=='], 'User B'));
        $adapter->handleMessage(new IRCMessage('UID', '003', ['UserC', '1', '1700000000', 'u3', 'h3', '003CCCCCC', '0', '+i', '*', '*', 'dGVzdA=='], 'User C'));
        $adapter->handleMessage(new IRCMessage('UID', '004', ['UserD', '1', '1700000000', 'u4', 'h4', '004DDDDDD', '0', '+i', '*', '*', 'dGVzdA=='], 'User D'));

        $chanName = new ChannelName('#test');
        $registry->markSyncCompleted($chanName->value);
        $channel = new Channel($chanName, '+nt', new DateTimeImmutable('@1700000000'));
        $channelRepo->save($channel);

        foreach (['001AAAAAA', '002BBBBBB', '003CCCCCC', '004DDDDDD'] as $uidStr) {
            $u = $userRepo->findByUid(new Uid($uidStr));
            self::assertNotNull($u);
            $u->addChannel($chanName);
            $channel->syncMember($u->uid, ChannelMemberRole::None);
        }
        $channelRepo->save($channel);

        self::assertCount(4, $userRepo->all());
        self::assertCount(4, $channel->getMembers());

        // SQUIT Leaf 1 by SID (002)
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['002'], 'Split'));

        self::assertNull($userRepo->findByUid(new Uid('002BBBBBB')));
        self::assertNull($userRepo->findByUid(new Uid('003CCCCCC')));
        self::assertNotNull($userRepo->findByUid(new Uid('001AAAAAA')));
        self::assertNotNull($userRepo->findByUid(new Uid('004DDDDDD')));
        self::assertCount(2, $userRepo->all());

        self::assertNull($channel->getMember(new Uid('002BBBBBB')));
        self::assertNull($channel->getMember(new Uid('003CCCCCC')));
        self::assertNotNull($channel->getMember(new Uid('001AAAAAA')));
        self::assertNotNull($channel->getMember(new Uid('004DDDDDD')));
        self::assertCount(2, $channel->getMembers());

        // Reconnect Leaf 1 and Leaf 2
        $adapter->handleMessage(new IRCMessage('SID', '001', ['leaf1.example.net', '1', '002'], 'Leaf 1'));
        $adapter->handleMessage(new IRCMessage('SID', '002', ['leaf2.example.net', '2', '003'], 'Leaf 2'));
        $adapter->handleMessage(new IRCMessage('UID', '002', ['UserB', '1', '1700000000', 'u2', 'h2', '002BBBBBB', '0', '+i', '*', '*', 'dGVzdA=='], 'User B'));
        $adapter->handleMessage(new IRCMessage('UID', '003', ['UserC', '1', '1700000000', 'u3', 'h3', '003CCCCCC', '0', '+i', '*', '*', 'dGVzdA=='], 'User C'));
        foreach (['002BBBBBB', '003CCCCCC'] as $uidStr) {
            $u = $userRepo->findByUid(new Uid($uidStr));
            self::assertNotNull($u);
            $u->addChannel($chanName);
            $channel->syncMember($u->uid, ChannelMemberRole::None);
        }
        $channelRepo->save($channel);
        self::assertCount(4, $userRepo->all());
        self::assertCount(4, $channel->getMembers());

        // SQUIT Leaf 1 by Name ('leaf1.example.net')
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['leaf1.example.net'], 'Split by name'));

        self::assertNull($userRepo->findByUid(new Uid('002BBBBBB')));
        self::assertNull($userRepo->findByUid(new Uid('003CCCCCC')));
        self::assertNotNull($userRepo->findByUid(new Uid('001AAAAAA')));
        self::assertNotNull($userRepo->findByUid(new Uid('004DDDDDD')));
        self::assertCount(2, $userRepo->all());
        self::assertCount(2, $channel->getMembers());

        // SQUIT Hub by Name ('hub.example.net')
        $adapter->handleMessage(new IRCMessage('SQUIT', null, ['hub.example.net'], 'Hub down'));
        self::assertSame([], $userRepo->all());
        self::assertCount(0, $channel->getMembers());
        self::assertNull($channelRepo->findByName($chanName));
        self::assertFalse($registry->isSyncCompleted($chanName->value));
    }
}
