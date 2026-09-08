<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\ChanServ\Application\Port\In\ChannelProjection;
use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbStoreInitializer;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbStoreInitializationListener;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Application\Port\In\GlineProjection;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(UdbStoreInitializer::class)]
final class UdbStoreInitializerTest extends TestCase
{
    private const string BCRYPT_HASH = '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe';

    private FakeUdbRecords $records;

    private FakeBlockStates $states;

    private UdbStoreInitializationListener $coordinator;

    protected function setUp(): void
    {
        $this->records = new FakeUdbRecords();
        $this->states = new FakeBlockStates();
        $this->coordinator = $this->createStub(UdbStoreInitializationListener::class);
    }

    private function createExporter(bool $withChannelAndGline = false): UdbRecordExporter
    {
        $nick = new NickProjection(7, 'david', self::BCRYPT_HASH, 'david.example.net');
        $nicks = $this->createStub(NickProjectionQuery::class);
        $nicks->method('all')->willReturn([$nick]);
        $nicks->method('findById')->willReturn($nick);

        $channels = $this->createStub(ChannelProjectionQuery::class);
        $glines = $this->createStub(GlineProjectionQuery::class);

        if ($withChannelAndGline) {
            $channels->method('all')->willReturn([
                new ChannelProjection(1, '#chan', 7, null, false, '', [], false, false, null, false, false, []),
            ]);
            $glines->method('active')->willReturn([
                new GlineProjection('*@bad.example', 'abuse', new DateTimeImmutable(), null),
            ]);
        }

        return new UdbRecordExporter(
            $nicks,
            $channels,
            $this->createStub(OperatorNetworkProjectionQuery::class),
            $glines,
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );
    }

    #[Test]
    public function getSubscribedEventsListensToNetworkSyncComplete(): void
    {
        self::assertSame(
            [NetworkSyncCompleteEvent::class => 'onNetworkSyncComplete'],
            UdbStoreInitializer::getSubscribedEvents(),
        );
    }

    #[Test]
    public function firstSyncSeedsNckFromSqlMarksAllSixBlocksAndNotifiesTheCoordinator(): void
    {
        $coordinator = $this->createMock(UdbStoreInitializationListener::class);
        $coordinator->expects($this->once())->method('onStoreInitialized');

        $initializer = new UdbStoreInitializer($this->records, $this->states, $this->createExporter(true), $coordinator);

        self::assertTrue($initializer->ensureInitialized());
        self::assertSame(
            ['david::pass' => 'crypt:' . self::BCRYPT_HASH, 'david::vhost' => 'david.example.net'],
            $this->records->blocks['N'],
        );
        self::assertSame(['#chan::founder' => 'david', '#chan::options' => '*8'], $this->records->recordsByBlock('C'));
        self::assertSame(['G::*@bad.example' => 'abuse', 'G::*@bad.example::reason' => 'abuse'], $this->records->recordsByBlock('K'));
        // I/S/L start empty and are never seeded from SQL.
        self::assertArrayNotHasKey('I', $this->records->blocks);
        self::assertArrayNotHasKey('S', $this->records->blocks);
        self::assertArrayNotHasKey('L', $this->records->blocks);
        self::assertCount(6, $this->states->states);
    }

    #[Test]
    public function alreadyInitializedStoreIsLeftUntouched(): void
    {
        foreach (UdbBlock::all() as $block) {
            $this->states->upsert($block->letter(), '00000000');
        }
        $coordinator = $this->createMock(UdbStoreInitializationListener::class);
        $coordinator->expects($this->never())->method('onStoreInitialized');

        $initializer = new UdbStoreInitializer($this->records, $this->states, $this->createExporter(), $coordinator);

        self::assertFalse($initializer->ensureInitialized());
        self::assertSame([], $this->records->blocks);
    }

    #[Test]
    public function networkSyncCompleteSeedsAndNotifiesOnlyWhenSomethingChanged(): void
    {
        $event = new NetworkSyncCompleteEvent(
            $this->createStub(ConnectionInterface::class),
            '002',
        );
        $coordinator = $this->createMock(UdbStoreInitializationListener::class);
        $coordinator->expects($this->once())->method('onStoreInitialized');

        $initializer = new UdbStoreInitializer($this->records, $this->states, $this->createExporter(), $coordinator);
        $initializer->onNetworkSyncComplete($event);
        $initializer->onNetworkSyncComplete($event);
    }

    #[Test]
    public function partiallyInitializedStoreSeedsOnlyMissingBlocks(): void
    {
        $this->states->upsert('N', '00000000');

        $initializer = new UdbStoreInitializer($this->records, $this->states, $this->createExporter(), $this->coordinator);
        $initializer->ensureInitialized();

        self::assertArrayNotHasKey('N', $this->records->blocks);
        self::assertCount(6, $this->states->states);
    }

    #[Test]
    public function unencodableSeedPathsAreDroppedDuringEncoding(): void
    {
        $initializer = new UdbStoreInitializer($this->records, $this->states, $this->createExporter(), $this->coordinator);

        $encode = new ReflectionMethod(UdbStoreInitializer::class, 'encodedRecords');
        $result = $encode->invoke($initializer, [
            'G::' . str_repeat('a', 5000) => 'x',
            'G::*@bad.example::reason' => 'spam',
            '#chan::topic' => '',
        ]);

        self::assertSame(['G::*@bad.example::reason' => 'spam'], $result);
    }
}
