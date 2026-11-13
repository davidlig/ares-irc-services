<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNetworkStateAdapter;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\ValueObject\ChannelName;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(UnrealStandaloneNetworkStateAdapter::class)]
#[CoversClass(UnrealUdbNetworkStateAdapter::class)]
#[CoversClass(InMemoryChannelRepository::class)]
#[CoversClass(Channel::class)]
final class UnrealCaseMappingTest extends TestCase
{
    #[Test]
    public function unrealReconnectWithoutCapabilityRestoresAsciiDefault(): void
    {
        foreach ([UnrealStandaloneNetworkStateAdapter::class, UnrealUdbNetworkStateAdapter::class] as $adapterClass) {
            $dispatcher = new EventDispatcher();
            $channels = new InMemoryChannelRepository();
            $dispatcher->addSubscriber($channels);
            $adapter = new $adapterClass($dispatcher);
            $adapter->handleMessage(IRCMessage::fromRawLine('PROTOCTL CASEMAPPING=rfc1459 SID=001'));
            $adapter->handleMessage(IRCMessage::fromRawLine('ERROR :disconnect'));
            $adapter->handleMessage(IRCMessage::fromRawLine('PROTOCTL SID=002'));
            $channel = new Channel(new ChannelName('#new[a]'), '', new DateTimeImmutable('@0'));
            $channels->save($channel);
            self::assertNull($channels->findByName(new ChannelName('#NEW{A}')));
            self::assertSame($channel, $channels->findByName(new ChannelName('#NEW[A]')));
        }
    }

    #[Test]
    public function negotiatedWireSettingsReachIndexes(): void
    {
        $dispatcher = new EventDispatcher();
        $channels = new InMemoryChannelRepository();
        $dispatcher->addSubscriber($channels);
        $channel = new Channel(new ChannelName('#room[a]'), '', new DateTimeImmutable('@0'));
        $channels->save($channel);
        foreach ([new UnrealStandaloneNetworkStateAdapter($dispatcher), new UnrealUdbNetworkStateAdapter($dispatcher)] as $adapter) {
            foreach (['rfc1459', 'ascii', 'strict-rfc1459', 'ascii'] as $mapping) {
                $adapter->handleMessage(new IRCMessage('PROTOCTL', null, ['CASEMAPPING=' . $mapping]));
                self::assertSame('ascii' === $mapping ? null : $channel, $channels->findByName(new ChannelName('#ROOM{A}')));
            }
            $adapter->handleMessage(new IRCMessage('PROTOCTL', null, ['CASEMAPPING=unknown']));
            self::assertNull($channels->findByName(new ChannelName('#ROOM{A}')));
        }
    }
}
