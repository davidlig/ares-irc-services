<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\PasswordMigrationStateInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickPasswordProvidedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordReceivedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber\UdbNickSyncSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbNickSyncSubscriber::class)]
final class UdbNickSyncSubscriberTest extends TestCase
{
    private function createSubscriber(
        $holder,
        $repo,
        $migrationState = null
    ): UdbNickSyncSubscriber {
        return new UdbNickSyncSubscriber(
            $holder,
            $repo,
            $migrationState ?? $this->createStub(PasswordMigrationStateInterface::class)
        );
    }

    public function testGetSubscribedEvents(): void
    {
        $events = UdbNickSyncSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(NickPasswordProvidedEvent::class, $events);
        $this->assertArrayHasKey(UdbSyncRequestedEvent::class, $events);
        $this->assertArrayHasKey(UdbRecordReceivedEvent::class, $events);
    }

    public function testOnPasswordProvidedNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(false);

        $sub = $this->createSubscriber($holder, $this->createStub(RegisteredNickRepositoryInterface::class));
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(1, 'nick', 'pass'));
    }

    public function testOnPasswordProvidedWithoutVhost(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $hash = hash('sha256', 'pass');
        $holder->expects($this->once())->method('writeLine')
               ->with("DB * INS N::nick::pass sha256:{$hash}");

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('nick');

        $sub = $this->createSubscriber($holder, $repo, $migrationState);
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(1, 'nick', 'pass'));
    }

    public function testOnPasswordProvidedWithVhost(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $hash = hash('sha256', 'pass');
        $holder->expects($this->exactly(2))->method('writeLine')
               ->with(self::anything());

        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getVhost')->willReturn('myvhost');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($nick);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('nick');

        $sub = $this->createSubscriber($holder, $repo, $migrationState);
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(1, 'nick', 'pass'));
    }

    public function testOnSyncRequestedNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(false);

        $sub = $this->createSubscriber($holder, $this->createStub(RegisteredNickRepositoryInterface::class));
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
    }

    public function testOnSyncRequestedWrongBlock(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $sub = $this->createSubscriber($holder, $this->createStub(RegisteredNickRepositoryInterface::class));
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
    }

    public function testOnSyncRequestedCorrectBlock(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')
               ->with('DB * REQ N');

        $sub = $this->createSubscriber($holder, $this->createStub(RegisteredNickRepositoryInterface::class));
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
    }

    public function testOnRecordReceivedNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(false);

        $sub = $this->createSubscriber($holder, $this->createStub(RegisteredNickRepositoryInterface::class));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::nick::pass', 'hash'));
    }

    public function testOnRecordReceivedWrongBlockOrMalformed(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->exactly(2))->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->never())->method('findByNick');

        $sub = $this->createSubscriber($holder, $repo);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::chan::founder', 'nick'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::malformed', 'val'));
    }

    public function testOnRecordReceivedNickFound(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $account = $this->createStub(RegisteredNick::class);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('nick')->willReturn($account);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('nick');

        $sub = $this->createSubscriber($holder, $repo, $migrationState);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::nick::pass', 'hash'));
    }

    public function testOnRecordReceivedNickNotFound(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * DEL N::nick');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('nick')->willReturn(null);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->never())->method('markAsMigrated');

        $sub = $this->createSubscriber($holder, $repo, $migrationState);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::nick::pass', 'hash'));
    }
}
