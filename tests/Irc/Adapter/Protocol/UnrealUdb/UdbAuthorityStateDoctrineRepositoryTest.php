<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Event\NetworkBurstCompleteEvent;
use App\Irc\Adapter\Out\Connection\ConnectionInterface;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbDatasetCheckpoint;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbAuthorityStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbBlockStateDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordDoctrineRepository;
use App\Irc\Adapter\Protocol\UnrealUdb\Persistence\Doctrine\UdbRecordMutationDoctrineStore;
use App\Irc\Adapter\Protocol\UnrealUdb\Reconciliation\UdbSnapshotProvider;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionCoordinator;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionLock;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbNickReservation;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbProtocolHandler;
use App\Irc\Adapter\Protocol\UnrealUdb\UnrealUdbRecordWriter;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use App\Irc\Domain\Server\ServerLink;
use App\Irc\Domain\ValueObject\Hostname;
use App\Irc\Domain\ValueObject\LinkPassword;
use App\Irc\Domain\ValueObject\Port;
use App\Irc\Domain\ValueObject\ServerName;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(UdbAuthorityStateDoctrineRepository::class)]
#[CoversClass(UdbSessionCoordinator::class)]
#[CoversClass(UnrealUdbProtocolHandler::class)]
final class UdbAuthorityStateDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function approvalReloadAndLocalStreamNeverEraseImportedCheckpoint(): void
    {
        $repository = new UdbAuthorityStateDoctrineRepository($this->entityManager);
        $repository->state()->recordImportedCheckpoint(new UdbDatasetCheckpoint(new UdbStreamOrigin('001', '1111111111111111'), UdbUnsignedDecimal::fromInt(41)));
        $this->entityManager->flush();
        $repository->approve(str_repeat('a', 64));
        $store = new UdbRecordMutationDoctrineStore($this->entityManager, new MockClock());
        $store->beginLocalStream(new UdbStreamOrigin('002', '2222222222222222'));
        $this->entityManager->clear();
        $state = $repository->state();
        self::assertTrue($state->isApproved());
        $imported = $state->getImportedCheckpoint();
        self::assertNotNull($imported);
        self::assertSame('001', $imported->origin->sid);
        self::assertSame('1111111111111111', $imported->origin->epoch);
        self::assertSame('41', (string) $imported->watermark);
        $local = $state->getDatasetCheckpoint();
        self::assertNotNull($local);
        self::assertSame('002', $local->origin->sid);
        self::assertSame('0', (string) $local->watermark);
    }

    #[Test]
    public function actualBurstReservationCallbackHasPreparedStreamBeforeAnyHello(): void
    {
        $states = new UdbBlockStateDoctrineRepository($this->entityManager);
        foreach (UdbBlock::all() as $block) {
            $states->upsert($block->letter(), UdbChecksum::empty($block), 0);
        }
        $store = new UdbRecordMutationDoctrineStore($this->entityManager, new MockClock());
        $coordinator = new UdbSessionCoordinator('002', $states, new UdbSnapshotProvider($this->entityManager->getConnection()), scheduler: new DeterministicLoopScheduler(), mutations: $store);
        $reservation = new UnrealUdbNickReservation(new UnrealUdbRecordWriter($coordinator, $store), new UdbRecordDoctrineRepository($this->entityManager), servicesVhost: 'services.test');
        $written = [];
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(static function (string $line) use (&$written): void { $written[] = $line; });
        $events = new EventDispatcher();
        $called = false;
        $events->addListener(NetworkBurstCompleteEvent::class, static function () use ($reservation, $coordinator, &$called, &$written): void {
            self::assertFalse($coordinator->isAuthorityReady());
            self::assertFalse((bool) array_filter($written, static fn (string $line): bool => str_contains($line, ' DB ') && str_contains($line, ' HEL ')));
            $reservation->reserveNick('NickServ', 'managed service');
            $called = true;
        }, 200);
        $lockDirectory = sys_get_temp_dir() . '/ares-burst-lock-' . bin2hex(random_bytes(8));
        $lock = new UdbSessionLock($lockDirectory);
        $handler = new UnrealUdbProtocolHandler('002', $coordinator, lock: $lock, eventDispatcher: $events);
        $link = new ServerLink(new ServerName('services.test'), new Hostname('127.0.0.1'), new Port(6667), new LinkPassword('dummy'), 'test');
        try {
            $handler->performHandshake($connection, $link);
            $handler->handleIncoming(new IRCMessage(command: 'PROTOCTL', params: ['SID=001']), $connection);
            $handler->handleIncoming(new IRCMessage(command: 'SERVER', params: ['ircd.test', '1']), $connection);
            $handler->handleIncoming(new IRCMessage(command: 'EOS', prefix: '001'), $connection);
            self::assertTrue($called);
            $checkpoint = new UdbAuthorityStateDoctrineRepository($this->entityManager)->state()->getDatasetCheckpoint();
            self::assertNotNull($checkpoint);
            self::assertSame($coordinator->epoch(), $checkpoint->origin->epoch);
            self::assertSame('3', (string) $checkpoint->watermark);
            $coordinator->prepareLocalStream();
            $checkpoint = new UdbAuthorityStateDoctrineRepository($this->entityManager)->state()->getDatasetCheckpoint();
            self::assertNotNull($checkpoint);
            self::assertSame('3', (string) $checkpoint->watermark);
        } finally {
            $handler->resetRemoteIdentity();
            $lock->release();
            @unlink($lockDirectory . '/udb.lock');
            @rmdir($lockDirectory);
        }
    }
}
