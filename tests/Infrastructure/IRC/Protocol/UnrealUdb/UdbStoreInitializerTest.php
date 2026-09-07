<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbRecordExporter;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionCoordinator;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbStoreInitializer;
use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

#[CoversClass(UdbStoreInitializer::class)]
final class UdbStoreInitializerTest extends TestCase
{
    private const string BCRYPT_HASH = '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe';

    private FakeUdbRecords $records;

    private FakeBlockStates $states;

    private UdbSessionCoordinator $coordinator;

    protected function setUp(): void
    {
        $this->records = new FakeUdbRecords();
        $this->states = new FakeBlockStates();
        $this->coordinator = $this->createStub(UdbSessionCoordinator::class);
    }

    private function createExporter(bool $withChannelAndGline = false): UdbRecordExporter
    {
        $nick = RegisteredNick::createPending(
            'david',
            self::BCRYPT_HASH,
            'david@example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            registeredAt: new DateTimeImmutable(),
        );
        $nick->activate();
        new ReflectionClass(RegisteredNick::class)->getProperty('id')->setValue($nick, 7);
        new ReflectionClass(RegisteredNick::class)->getProperty('vhost')->setValue($nick, 'david.example.net');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('all')->willReturn([$nick]);
        $nickRepo->method('findById')->willReturn($nick);

        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $glineRepo = $this->createStub(GlineRepositoryInterface::class);

        if ($withChannelAndGline) {
            $channel = RegisteredChannel::register('#chan', 7, 'desc');
            new ReflectionClass(RegisteredChannel::class)->getProperty('id')->setValue($channel, 1);
            $channelRepo->method('listAll')->willReturn([$channel]);
            $glineRepo->method('findActive')->willReturn([Gline::create('*@bad.example', null, 'abuse')]);
        }

        return new UdbRecordExporter(
            $nickRepo,
            $channelRepo,
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $glineRepo,
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
        $coordinator = $this->createMock(UdbSessionCoordinator::class);
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
        $coordinator = $this->createMock(UdbSessionCoordinator::class);
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
        $coordinator = $this->createMock(UdbSessionCoordinator::class);
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
