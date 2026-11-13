<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Event\ConnectionEstablishedEvent;
use App\Irc\Adapter\Event\ConnectionLostEvent;
use App\Irc\Adapter\Network\CaseMappingSessionSubscriber;
use App\Irc\Adapter\Network\ChannelSyncCompletedRegistry;
use App\Irc\Adapter\Network\Event\CaseMappingChangedEvent;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdProtocolHandler;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\CaseMapping;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use App\Irc\Domain\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(InspIRCdProtocolHandler::class)]
#[CoversClass(CaseMappingSessionSubscriber::class)]
#[CoversClass(CaseMapping::class)]
#[CoversClass(CaseMappingChangedEvent::class)]
#[CoversClass(Channel::class)]
#[CoversClass(NetworkUser::class)]
#[CoversClass(InMemoryChannelRepository::class)]
#[CoversClass(InMemoryNetworkUserRepository::class)]
#[CoversClass(ChannelSyncCompletedRegistry::class)]
final class NetworkCaseMappingTest extends TestCase
{
    #[Test]
    public function transportLifecycleResetsNegotiatedMapping(): void
    {
        $dispatcher = new EventDispatcher();
        $channels = new InMemoryChannelRepository();
        $dispatcher->addSubscriber($channels);
        $dispatcher->addSubscriber(new CaseMappingSessionSubscriber($dispatcher));
        $link = new ServerLink(new ServerName('test.example'), new Hostname('localhost'), new Port(6667), new LinkPassword('synthetic'), 'Test');
        $channel = new Channel(new ChannelName('#room[a]'), '', new DateTimeImmutable('@0'));
        $channels->save($channel);
        foreach ([new ConnectionEstablishedEvent($link), new ConnectionLostEvent($link, 'closed')] as $event) {
            $dispatcher->dispatch(new CaseMappingChangedEvent(CaseMapping::Rfc1459));
            self::assertSame($channel, $channels->findByName(new ChannelName('#ROOM{A}')));
            $dispatcher->dispatch($event);
            self::assertNull($channels->findByName(new ChannelName('#ROOM{A}')));
            self::assertSame($channel, $channels->findByName(new ChannelName('#ROOM[A]')));
        }
    }

    #[Test]
    public function negotiatedWireSettingsReachAllIndexes(): void
    {
        $dispatcher = new EventDispatcher();
        $users = new InMemoryNetworkUserRepository();
        $channels = new InMemoryChannelRepository();
        $sync = new ChannelSyncCompletedRegistry();
        foreach ([$users, $channels, $sync] as $subscriber) {
            $dispatcher->addSubscriber($subscriber);
        }
        $channel = new Channel(new ChannelName('#room[a]'), '', new DateTimeImmutable('@0'));
        $channels->save($channel);
        $handler = new InspIRCdProtocolHandler(eventDispatcher: $dispatcher);
        $connection = $this->createStub(ConnectionInterface::class);
        foreach (['rfc1459', 'ascii'] as $mapping) {
            foreach (['CAPAB START 1206', 'CAPAB MODULES :services account chghost cloak', 'CAPAB CAPABILITIES :CASEMAPPING=' . $mapping, 'CAPAB END'] as $line) {
                $handler->handleIncoming(IRCMessage::fromRawLine($line), $connection);
            }
            self::assertSame('ascii' === $mapping ? null : $channel, $channels->findByName(new ChannelName('#ROOM{A}')));
        }
    }

    #[Test]
    public function negotiatedMappingReindexesExistingStateAndFutureMutations(): void
    {
        $users = new InMemoryNetworkUserRepository();
        $channels = new InMemoryChannelRepository();
        $sync = new ChannelSyncCompletedRegistry();
        $name = new ChannelName('#room[a]');
        $alias = new ChannelName('#ROOM{A}');
        $user = new NetworkUser(new Uid('001AAAAAA'), new Nick('nick[a]'), new Ident('ident'), 'host', 'cloak', '*', '+', new DateTimeImmutable('@0'), 'Real', '001', '*');
        $user->addChannel($name);
        $users->add($user);
        $channel = new Channel($name, '', new DateTimeImmutable('@0'));
        $channel->addBan('nick[a]!*@*');
        $channels->save($channel);
        $sync->markSyncCompleted($name->value);
        $timestamp = $sync->getSyncCompletedAt($name->value);
        self::assertNull($users->findByNick(new Nick('NICK{A}')));
        self::assertNull($channels->findByName($alias));
        self::assertFalse($sync->isSyncCompleted($alias->value));
        foreach ([CaseMapping::Rfc1459, CaseMapping::Rfc1459] as $mapping) {
            $event = new CaseMappingChangedEvent($mapping);
            $users->onCaseMappingChanged($event);
            $channels->onCaseMappingChanged($event);
            $sync->onCaseMappingChanged($event);
        }
        self::assertSame($user, $users->findByNick(new Nick('NICK{A}')));
        self::assertSame($channel, $channels->findByName($alias));
        self::assertSame($timestamp, $sync->getSyncCompletedAt($alias->value));
        self::assertTrue($sync->isSyncCompleted($alias->value));
        $user->removeChannel($alias);
        self::assertSame([], $user->getChannelNames());
        $user->addChannel($alias);
        $user->removeChannel($name);
        self::assertSame([], $user->getChannelNames());
        $channel->removeBan('NICK{A}!*@*');
        self::assertSame([], $channel->getBans());
        $users->updateNick($user->uid, new Nick('NICK{A}'), new Nick('other[b]'));
        self::assertNull($users->findByNick(new Nick('nick[a]')));
        self::assertSame($user, $users->findByNick(new Nick('OTHER{B}')));
        $users->removeByUid($user->uid);
        $channels->remove($alias);
        $sync->remove($alias->value);
        self::assertSame([], $users->all());
        self::assertSame([], $channels->all());
        self::assertNull($sync->getSyncCompletedAt($name->value));
    }
}
