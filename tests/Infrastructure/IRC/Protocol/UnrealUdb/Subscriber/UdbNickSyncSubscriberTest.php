<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\PasswordMigrationStateInterface;
use App\Application\Port\UdbRecordWriterInterface;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Event\OperIrcopChangedEvent;
use App\Domain\OperServ\Event\OperRoleForcedVhostChangedEvent;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber\UdbNickSyncSubscriber;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbRecordExporter;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UdbSessionStateInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\PublishedEvent\NickDropEvent;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;
use App\NickServ\Application\PublishedEvent\NickSuspendedEvent;
use App\NickServ\Application\PublishedEvent\NickUnsuspendedEvent;
use App\NickServ\Application\PublishedEvent\NickVhostChangedEvent;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(UdbNickSyncSubscriber::class)]
final class UdbNickSyncSubscriberTest extends TestCase
{
    private function createSubscriber(
        ?RegisteredNickRepositoryInterface $repo = null,
        ?PasswordMigrationStateInterface $migrationState = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
        ?UdbRecordWriterInterface $writer = null,
    ): UdbNickSyncSubscriber {
        $defaultWriter = $this->createStub(UdbRecordWriterInterface::class);
        $defaultWriter->method('insert')->willReturn(true);
        $defaultWriter->method('delete')->willReturn(true);
        $writer ??= $defaultWriter;

        return new UdbNickSyncSubscriber(
            $writer,
            $repo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $migrationState ?? $this->createStub(PasswordMigrationStateInterface::class),
            $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createExporter($repo, $ircopRepo),
        );
    }

    private function createExporter(
        ?RegisteredNickRepositoryInterface $repo = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
    ): UdbRecordExporter {
        return new UdbRecordExporter(
            $repo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(GlineRepositoryInterface::class),
            $this->createStub(ChannelLookupPort::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
        );
    }

    private function createNick(string $nickname, ?string $vhost = null): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'argon2id:$argon2id$hash', $nickname . '@example.com', 'en', new DateTimeImmutable('+1 hour'), new DateTimeImmutable());
        $nick->activate();
        new ReflectionClass(RegisteredNick::class)->getProperty('id')->setValue($nick, 7);
        if (null !== $vhost) {
            new ReflectionClass(RegisteredNick::class)->getProperty('vhost')->setValue($nick, $vhost);
        }

        return $nick;
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
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
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
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'nick::pass', $this->stringStartsWith('crypt:$2y$'));

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onPasswordHashAvailable(new NickPasswordHashAvailable(999, 'nick', '$2y$12$V1fmubjfLQd.sMvEU4x.5.hjN6wtGG1aNhiJqy.dc0O0sfKFzyLGe'));
    }

    #[Test]
    public function onVhostChangedWritesVhostWhenPresent(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->createNick('nick', 'nick.tld'));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'nick::vhost', 'nick.tld');

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onVhostChanged(new NickVhostChangedEvent(7, 'nick', 'nick.tld'));
    }

    #[Test]
    public function onVhostChangedDeletesVhostWhenAbsent(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->createNick('nick'));

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('N', 'nick::vhost');

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onVhostChanged(new NickVhostChangedEvent(7, 'nick', null));
    }

    #[Test]
    public function onVhostChangedIsSkippedForUnknownAccounts(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);
        $writer->expects($this->never())->method('delete')->willReturn(true);

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onVhostChanged(new NickVhostChangedEvent(7, 'nick', null));
    }

    #[Test]
    public function onNickSuspendedWritesTheSuspendedRecord(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'nick::suspended', 'bad conduct');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onNickSuspended(new NickSuspendedEvent(7, 'nick', 'bad conduct', '30d', null, 'root', null, '127.0.0.1', 'host', new DateTimeImmutable()));
    }

    #[Test]
    public function onNickUnsuspendedDeletesTheSuspendedRecord(): void
    {
        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('delete')->willReturn(true)->with('N', 'nick::suspended');

        $sub = $this->createSubscriber(writer: $writer);
        $sub->onNickUnsuspended(new NickUnsuspendedEvent(7, 'nick', 'root', null, '127.0.0.1', 'host', new DateTimeImmutable()));
    }

    #[Test]
    public function onOperRoleForcedVhostChangedRefreshesEveryIrcopOfTheRole(): void
    {
        $nick = $this->createNick('oper1', 'forced.tld');
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByRoleId')->willReturn([
            OperIrcop::create(7, OperRole::create('netadmin')),
        ]);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->once())->method('insert')->willReturn(true)->with('N', 'oper1::vhost', 'forced.tld');

        $sub = $this->createSubscriber(repo: $repo, ircopRepo: $ircopRepo, writer: $writer);
        $sub->onOperRoleForcedVhostChanged(new OperRoleForcedVhostChangedEvent(3, 'forced.tld'));
    }

    #[Test]
    public function onOperIrcopChangedDeletesOperRecordWhenOperclassIsMissing(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($this->createNick('oper1'));

        $role = OperRole::create('Helper');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(OperIrcop::create(7, $role));

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
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($nick);

        $role = OperRole::create('NetAdmin');
        $role->changeOperclass('services:netadmin');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(OperIrcop::create(7, $role));

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
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($nick);

        $role = OperRole::create('NetAdmin');
        $role->changeOperclass('services:netadmin');
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(OperIrcop::create(7, $role));

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
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
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
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $writer = $this->createMock(UdbRecordWriterInterface::class);
        $writer->expects($this->never())->method('insert')->willReturn(true);
        $writer->expects($this->never())->method('delete')->willReturn(true);

        $sub = $this->createSubscriber(repo: $repo, writer: $writer);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(7, 'oper1'));
    }
}
