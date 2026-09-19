<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\ChanServ\Application\Port\In\ChannelProjectionQuery;
use App\Irc\Adapter\Protocol\UnrealUdb\Session\UdbSessionStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\PasswordMigrationStateInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbChannelSuspendReasonResolver;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbNickSyncSubscriber;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordExporter;
use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbRecordWriterInterface;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\NickServ\Application\PublishedEvent\NickDropEvent;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\PublishedEvent\NickSuspendedEvent;
use App\NickServ\Application\PublishedEvent\NickUnsuspendedEvent;
use App\NickServ\Application\PublishedEvent\NickVhostChangedEvent;
use App\OperServ\Application\Port\In\GlineProjectionQuery;
use App\OperServ\Application\Port\In\OperatorNetworkProjection;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\OperServ\Application\PublishedEvent\OperIrcopChangedEvent;
use App\OperServ\Application\PublishedEvent\OperRoleForcedVhostChangedEvent;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(UdbNickSyncSubscriber::class)]
final class UdbNickSyncSubscriberTest extends TestCase
{
    private function createSubscriber(
        ?NickProjectionQuery $repo = null,
        ?PasswordMigrationStateInterface $migrationState = null,
        ?OperatorNetworkProjectionQuery $ircopRepo = null,
        ?UdbRecordWriterInterface $writer = null,
    ): UdbNickSyncSubscriber {
        $defaultWriter = $this->createStub(UdbRecordWriterInterface::class);
        $defaultWriter->method('insert')->willReturn(true);
        $defaultWriter->method('delete')->willReturn(true);
        $writer ??= $defaultWriter;

        return new UdbNickSyncSubscriber(
            $writer,
            $repo ?? $this->createStub(NickProjectionQuery::class),
            $migrationState ?? $this->createStub(PasswordMigrationStateInterface::class),
            $ircopRepo ?? $this->createStub(OperatorNetworkProjectionQuery::class),
            $this->createExporter($repo, $ircopRepo),
        );
    }

    private function createExporter(
        ?NickProjectionQuery $repo = null,
        ?OperatorNetworkProjectionQuery $ircopRepo = null,
    ): UdbRecordExporter {
        return new UdbRecordExporter(
            $repo ?? $this->createStub(NickProjectionQuery::class),
            $this->createStub(ChannelProjectionQuery::class),
            $ircopRepo ?? $this->createStub(OperatorNetworkProjectionQuery::class),
            $this->createStub(GlineProjectionQuery::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            new UdbChannelSuspendReasonResolver($this->createStub(TranslatorInterface::class), 'ChanServ'),
        );
    }

    private function createNick(string $nickname, ?string $vhost = null): NickProjection
    {
        return new NickProjection(7, $nickname, 'argon2id:$argon2id$hash', $vhost, false, null, false);
    }

    #[Test]
    public function getSubscribedEventsExportsOnlyDomainEvents(): void
    {
        $events = UdbNickSyncSubscriber::getSubscribedEvents();

        self::assertSame([
            NickPasswordHashAvailable::class => 'onPasswordHashAvailable',
            NickVhostChangedEvent::class => 'onVhostChanged',
            NickSuspendedEvent::class => 'onNickSuspended',
            NickUnsuspendedEvent::class => 'onNickUnsuspended',
            NickDropEvent::class => 'onNickDrop',
            OperRoleForcedVhostChangedEvent::class => 'onOperRoleForcedVhostChanged',
            OperIrcopChangedEvent::class => 'onOperIrcopChanged',
        ], $events);
    }

    #[Test]
    public function onNickDropDeletesTheWholeProfile(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('N', 'nick');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onNickDrop(new NickDropEvent(1, 'nick', 'nick', 'manual', new DateTimeImmutable()));
    }

    #[Test]
    public function onPasswordHashAvailableMarksMigrationAndWritesUdbCompatibleHash(): void
    {
        $bcryptHash = '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe';
        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('nick');

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'nick::pass', 'crypt:' . $bcryptHash);

        $sub = $this->createSubscriber(migrationState: $migrationState, writer: $writer);
        $sub->onPasswordHashAvailable(new NickPasswordHashAvailable(null, 'nick', $bcryptHash));
    }

    #[Test]
    public function onPasswordHashAvailableSkipsTheRecordWhenTheHashIsNotProjectable(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onPasswordHashAvailable(new NickPasswordHashAvailable(null, 'nick', 'md5:deadbeef'));
    }

    #[Test]
    public function onPasswordHashAvailableSkipsTheRecordWhenTheHashIsNull(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onPasswordHashAvailable(new NickPasswordHashAvailable(null, 'nick', null));
    }

    #[Test]
    public function onPasswordHashAvailableRefreshesVhostWhenNickExists(): void
    {
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn($this->createNick('nick', 'nick.tld'));

        $inserts = [];
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->exactly(2))->method('insert')->willReturnCallback(
            static function (string $block, string $path, string $value) use (&$inserts): bool {
                $inserts[] = [$block, $path, $value];

                return true;
            },
        );

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onPasswordHashAvailable(new NickPasswordHashAvailable(7, 'nick', '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe'));

        self::assertSame('N', $inserts[0][0]);
        self::assertSame('nick::pass', $inserts[0][1]);
        self::assertStringStartsWith('crypt:$2y$', $inserts[0][2]);
        self::assertSame(['N', 'nick::vhost', 'nick.tld'], $inserts[1]);
    }

    #[Test]
    public function onPasswordHashAvailableIsSkippedWhenTheNickVanished(): void
    {
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'nick::pass', $this->stringStartsWith('crypt:$2y$'));

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onPasswordHashAvailable(new NickPasswordHashAvailable(999, 'nick', '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe'));
    }

    #[Test]
    public function onVhostChangedWritesVhostWhenPresent(): void
    {
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn($this->createNick('nick', 'nick.tld'));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'nick::vhost', 'nick.tld');

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onVhostChanged(new NickVhostChangedEvent(7, 'nick', 'nick.tld'));
    }

    #[Test]
    public function onVhostChangedDeletesVhostWhenAbsent(): void
    {
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn($this->createNick('nick'));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('N', 'nick::vhost');

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onVhostChanged(new NickVhostChangedEvent(7, 'nick', null));
    }

    #[Test]
    public function onVhostChangedIsSkippedForUnknownAccounts(): void
    {
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);
        $writer->expects($this->never())->method('delete')->willReturn(true);

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onVhostChanged(new NickVhostChangedEvent(7, 'nick', null));
    }

    #[Test]
    public function onNickSuspendedWritesTheSuspendRecord(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'nick::suspend', 'bad conduct');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onNickSuspended(new NickSuspendedEvent(7, 'nick', 'bad conduct', '30d', null, 'root', null, '127.0.0.1', 'host', new DateTimeImmutable()));
    }

    #[Test]
    public function onNickUnsuspendedDeletesTheSuspendRecord(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('N', 'nick::suspend');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onNickUnsuspended(new NickUnsuspendedEvent(7, 'nick', 'root', null, '127.0.0.1', 'host', new DateTimeImmutable()));
    }

    #[Test]
    public function onOperRoleForcedVhostChangedRefreshesEveryIrcopOfTheRole(): void
    {
        $nick = $this->createNick('oper1');
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn($nick);

        $ircopRepo = $this->createStub(OperatorNetworkProjectionQuery::class);
        $ircopRepo->method('findNickIdsByRoleId')->willReturn([7]);
        $ircopRepo->method('findForNick')->willReturn(new OperatorNetworkProjection(7, 'forced.tld', null));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'oper1::vhost', 'forced.tld');

        $sub = $this->createSubscriber(repo: $repo, ircopRepo: $ircopRepo, writer: $writer);
        $sub->onOperRoleForcedVhostChanged(new OperRoleForcedVhostChangedEvent(3, 'forced.tld'));
    }

    #[Test]
    public function onOperIrcopChangedDeletesOperRecordWhenOperclassIsMissing(): void
    {
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn($this->createNick('oper1'));

        $ircopRepo = $this->createStub(OperatorNetworkProjectionQuery::class);
        $ircopRepo->method('findForNick')->willReturn(new OperatorNetworkProjection(7, null, null));

        $deletes = [];
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->exactly(2))->method('delete')->willReturnCallback(
            static function (string $block, string $path) use (&$deletes): bool {
                $deletes[] = [$block, $path];

                return true;
            },
        );

        $sub = $this->createSubscriber(repo: $repo, ircopRepo: $ircopRepo, writer: $writer);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(7, 'oper1'));

        self::assertSame([
            ['N', 'oper1::vhost'],
            ['N', 'oper1::oper'],
        ], $deletes);
    }

    #[Test]
    public function onOperIrcopChangedDeletesOperRecordWhenOperclassIsNotGloballyAvailable(): void
    {
        $nick = $this->createNick('oper1');
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn($nick);

        $ircopRepo = $this->createStub(OperatorNetworkProjectionQuery::class);
        $ircopRepo->method('findForNick')->willReturn(new OperatorNetworkProjection(7, null, 'services:netadmin'));

        $sessionState = $this->createStub(UdbSessionStateInterface::class);
        $sessionState->method('isOperclassGloballyAvailable')->willReturn(false);

        $deletes = [];
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->method('delete')->willReturnCallback(
            static function (string $block, string $path) use (&$deletes): bool {
                $deletes[] = [$block, $path];

                return true;
            },
        );
        $writer->expects($this->never())->method('insert');

        $sub = new UdbNickSyncSubscriber(
            $writer,
            $repo,
            $this->createStub(PasswordMigrationStateInterface::class),
            $ircopRepo,
            $this->createExporter($repo, $ircopRepo),
            $sessionState,
        );
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(7, 'oper1'));

        self::assertSame([
            ['N', 'oper1::vhost'],
            ['N', 'oper1::oper'],
        ], $deletes);
    }

    #[Test]
    public function onOperIrcopChangedWritesOperRecordAndRefreshesVhost(): void
    {
        $nick = $this->createNick('oper1', 'oper.tld');
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn($nick);

        $ircopRepo = $this->createStub(OperatorNetworkProjectionQuery::class);
        $ircopRepo->method('findForNick')->willReturn(new OperatorNetworkProjection(7, null, 'services:netadmin'));

        $inserts = [];
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->exactly(2))->method('insert')->willReturnCallback(
            static function (string $block, string $path, string $value) use (&$inserts): bool {
                $inserts[] = [$block, $path, $value];

                return true;
            },
        );

        $sub = $this->createSubscriber(repo: $repo, ircopRepo: $ircopRepo, writer: $writer);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(7, 'oper1'));

        self::assertSame([
            ['N', 'oper1::vhost', 'oper.tld'],
            ['N', 'oper1::oper', 'services:netadmin'],
        ], $inserts);
    }

    #[Test]
    public function onOperIrcopChangedDeletesOperRecordWhenRemoved(): void
    {
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn($this->createNick('oper1'));

        $deletes = [];
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->exactly(2))->method('delete')->willReturnCallback(
            static function (string $block, string $path) use (&$deletes): bool {
                $deletes[] = [$block, $path];

                return true;
            },
        );

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(7, 'oper1'));

        self::assertSame([
            ['N', 'oper1::vhost'],
            ['N', 'oper1::oper'],
        ], $deletes);
    }

    #[Test]
    public function onOperIrcopChangedIsSkippedForUnknownAccounts(): void
    {
        $repo = $this->createStub(NickProjectionQuery::class);
        $repo->method('findById')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);
        $writer->expects($this->never())->method('delete')->willReturn(true);

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(7, 'oper1'));
    }
}
