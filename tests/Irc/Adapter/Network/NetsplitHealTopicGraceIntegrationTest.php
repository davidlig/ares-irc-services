<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\ChannelSyncCompletedMarkerSubscriber;
use App\Irc\Adapter\Network\ChannelSyncCompletedRegistry;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Network\NetworkStateSubscriber;
use App\Irc\Adapter\Network\ServerBurstRegistry;
use App\Irc\Adapter\Network\ServerDelinkedSubscriber;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNetworkStateAdapter;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\NetworkStateAdapterInterface;
use App\Irc\Adapter\Protocol\UnrealStandalone\UnrealStandaloneNetworkStateAdapter;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNetworkStateAdapter;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Domain\Event\ExistingChannelJoinObservedEvent;
use App\Irc\Domain\ValueObject\ChannelName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The topic grace window (sync-completed marker) re-arms on an equal-TS join to an existing
 * channel only while the originating server is still bursting (initial link or netsplit heal).
 */
#[CoversClass(ServerBurstRegistry::class)]
#[CoversClass(ChannelSyncCompletedMarkerSubscriber::class)]
#[CoversClass(NetworkEventEnricher::class)]
#[CoversClass(ExistingChannelJoinObservedEvent::class)]
#[CoversClass(InspIRCdNetworkStateAdapter::class)]
#[CoversClass(UnrealStandaloneNetworkStateAdapter::class)]
#[CoversClass(UnrealUdbNetworkStateAdapter::class)]
final class NetsplitHealTopicGraceIntegrationTest extends TestCase
{
    private const string CHANNEL = '#test';

    private ChannelSyncCompletedRegistry $markers;

    private ServerBurstRegistry $bursts;

    private InMemoryChannelRepository $channels;

    private EventDispatcher $dispatcher;

    /** @return iterable<string, array{class-string<UnrealStandaloneNetworkStateAdapter|UnrealUdbNetworkStateAdapter>}> */
    public static function unrealAdapters(): iterable
    {
        yield 'unreal standalone' => [UnrealStandaloneNetworkStateAdapter::class];
        yield 'unreal udb' => [UnrealUdbNetworkStateAdapter::class];
    }

    /** @param class-string<UnrealStandaloneNetworkStateAdapter|UnrealUdbNetworkStateAdapter> $adapterClass */
    #[Test]
    #[DataProvider('unrealAdapters')]
    public function unrealReArmsOnlyForJoinsFromABurstingServer(string $adapterClass): void
    {
        $adapter = new $adapterClass($this->dispatcher, burstRegistry: $this->bursts);

        // Initial link: the uplink 001 and its leaf 002 burst; Unreal splits a channel over several SJOIN lines.
        $this->feed($adapter, 'PROTOCTL NOQUIT SID=001');
        $this->feed($adapter, 'SERVER hub.example.net 1 :Hub');
        $this->feed($adapter, ':001 SID leaf1.example.net 2 002 :Leaf 1');
        $this->feed($adapter, ':001 SJOIN 1700000000 #test +nt :@001AAAAAA');
        self::assertTrue($this->markers->isSyncCompleted(self::CHANNEL));
        $this->assertJoinReArms(true, $adapter, ':001 SJOIN 1700000000 #test :002BBBBBB');
        $this->feed($adapter, ':002 EOS');
        $this->feed($adapter, ':001 EOS');

        // (c) An ordinary remote JOIN after end-of-burst is relayed as an equal-TS SJOIN: no re-arm.
        $this->assertJoinReArms(false, $adapter, ':001 SJOIN 1700000000 #test :001CCCCCC');
        $this->assertJoinReArms(false, $adapter, ':002 SJOIN 1700000000 #test :002DDDDDD');

        // Netsplit and heal of leaf 002.
        $this->feed($adapter, ':001 SQUIT 002 :Split');
        self::assertFalse($this->bursts->isBursting('002'));
        $this->feed($adapter, ':001 SID leaf1.example.net 2 002 :Leaf 1');
        $this->assertJoinReArms(true, $adapter, ':002 SJOIN 1700000000 #test :002BBBBBB'); // (a)
        $this->feed($adapter, ':002 EOS');
        $this->assertJoinReArms(false, $adapter, ':002 SJOIN 1700000000 #test :002EEEEEE'); // (b)
    }

    #[Test]
    public function inspircdReArmsOnlyForJoinsFromABurstingServer(): void
    {
        $adapter = new InspIRCdNetworkStateAdapter($this->dispatcher, burstRegistry: $this->bursts);

        // Initial link: the uplink 0A1 and its leaf 0A2 burst.
        $this->feed($adapter, 'SERVER hub.example.net password 0A1 :Hub');
        $this->feed($adapter, ':0A1 SERVER leaf1.example.net 0A2 burst=1700000000000 hidden=0 :Leaf 1');
        $this->feed($adapter, ':0A1 FJOIN #test 1700000000 +nt :o,0A1AAAAAA:1');
        self::assertTrue($this->markers->isSyncCompleted(self::CHANNEL));
        $this->assertJoinReArms(true, $adapter, ':0A1 FJOIN #test 1700000000 +nt :,0A2BBBBBB:2');
        $this->feed($adapter, ':0A1 ENDBURST');

        // (c) Ordinary post-burst joins (IJOIN carrying the channel TS) from non-bursting servers: no re-arm.
        $this->assertJoinReArms(false, $adapter, ':0A1CCCCCC IJOIN #test 3 1700000000');
        $this->assertJoinReArms(false, $adapter, ':0A2DDDDDD IJOIN #test 4 1700000000');

        // Netsplit and heal of leaf 0A2 (with its own leaf 0A3 behind it).
        $this->feed($adapter, ':0A1 SQUIT 0A2 :Split');
        self::assertFalse($this->bursts->isBursting('0A2'));
        $this->feed($adapter, ':0A1 SERVER leaf1.example.net 0A2 burst=1700000100000 hidden=0 :Leaf 1');
        $this->feed($adapter, ':0A2 SERVER leaf2.example.net 0A3 burst=1700000100000 hidden=0 :Leaf 2');
        $this->assertJoinReArms(true, $adapter, ':0A2 FJOIN #test 1700000000 +nt :,0A2BBBBBB:5,0A3FFFFFF:6'); // (a)
        $this->feed($adapter, ':0A2 ENDBURST');
        $this->assertJoinReArms(false, $adapter, ':0A2 FJOIN #test 1700000000 +nt :,0A2EEEEEE:7'); // (b)
        $this->assertJoinReArms(false, $adapter, ':0A3GGGGGG IJOIN #test 8 1700000000');
    }

    #[Test]
    public function inspircdServerIntroducedWithoutBurstKeyIsNotBursting(): void
    {
        $adapter = new InspIRCdNetworkStateAdapter($this->dispatcher, burstRegistry: $this->bursts);
        $this->feed($adapter, 'SERVER hub.example.net password 0A1 :Hub');
        $this->feed($adapter, ':0A1 SERVER leaf1.example.net 0A2 hidden=0 :Leaf 1');

        self::assertTrue($this->bursts->isBursting('0A1'));
        self::assertFalse($this->bursts->isBursting('0A2'));
    }

    /** @param class-string<UnrealStandaloneNetworkStateAdapter|UnrealUdbNetworkStateAdapter> $adapterClass */
    #[Test]
    #[DataProvider('unrealAdapters')]
    public function unrealUplinkSquitAndErrorForgetBurstState(string $adapterClass): void
    {
        $adapter = new $adapterClass($this->dispatcher, burstRegistry: $this->bursts);
        $this->feed($adapter, 'PROTOCTL SID=001');
        $this->feed($adapter, ':001 SID leaf1.example.net 2 002 :Leaf 1');
        $this->feed($adapter, ':001 SQUIT 001 :Uplink lost');
        self::assertFalse($this->bursts->isBursting('001'));
        self::assertFalse($this->bursts->isBursting('002'));

        $this->feed($adapter, 'PROTOCTL SID=001');
        $this->feed($adapter, ':001 SID leaf1.example.net 2 002 :Leaf 1');
        $this->feed($adapter, 'ERROR :Closing link');
        self::assertFalse($this->bursts->isBursting('001'));
        self::assertFalse($this->bursts->isBursting('002'));
    }

    #[Test]
    public function inspircdUplinkSquitAndErrorForgetBurstState(): void
    {
        $adapter = new InspIRCdNetworkStateAdapter($this->dispatcher, burstRegistry: $this->bursts);
        $this->feed($adapter, 'SERVER hub.example.net password 0A1 :Hub');
        $this->feed($adapter, ':0A1 SERVER leaf1.example.net 0A2 burst=1700000000000 hidden=0 :Leaf 1');
        $this->feed($adapter, ':0A1 SQUIT hub.example.net :Uplink lost');
        self::assertFalse($this->bursts->isBursting('0A1'));
        self::assertFalse($this->bursts->isBursting('0A2'));

        $this->feed($adapter, 'SERVER hub.example.net password 0A1 :Hub');
        $this->feed($adapter, ':0A1 SERVER leaf1.example.net 0A2 burst=1700000000000 hidden=0 :Leaf 1');
        $this->feed($adapter, 'ERROR :Closing link');
        self::assertFalse($this->bursts->isBursting('0A1'));
        self::assertFalse($this->bursts->isBursting('0A2'));
    }

    protected function setUp(): void
    {
        $this->dispatcher = new EventDispatcher();
        $this->markers = new ChannelSyncCompletedRegistry();
        $this->bursts = new ServerBurstRegistry();
        $this->channels = new InMemoryChannelRepository();
        $users = new InMemoryNetworkUserRepository();
        $this->dispatcher->addSubscriber($this->bursts);
        $this->dispatcher->addSubscriber(new ServerDelinkedSubscriber($users, $this->dispatcher));
        $this->dispatcher->addSubscriber(new NetworkStateSubscriber($users, $this->channels, $this->markers));
        $this->dispatcher->addSubscriber(new NetworkEventEnricher(
            $this->channels,
            $users,
            $this->dispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistry::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
        ));
        $this->dispatcher->addSubscriber(new ChannelSyncCompletedMarkerSubscriber($this->markers));
    }

    private function assertJoinReArms(bool $expected, NetworkStateAdapterInterface $adapter, string $joinLine): void
    {
        $this->markers->remove(self::CHANNEL);

        $this->feed($adapter, $joinLine);

        self::assertSame($expected, $this->markers->isSyncCompleted(self::CHANNEL), $joinLine);
        self::assertSame(1700000000, $this->channels->findByName(new ChannelName(self::CHANNEL))?->getCreatedAt()->getTimestamp());
    }

    private function feed(NetworkStateAdapterInterface $adapter, string $line): void
    {
        $adapter->handleMessage(IRCMessage::fromRawLine($line));
    }
}
