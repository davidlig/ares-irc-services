<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\PasswordMigrationStateInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\UdbRecordWriterInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Event\NickPasswordProvidedEvent;
use App\Domain\NickServ\Event\NickVhostChangedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Event\OperIrcopChangedEvent;
use App\Domain\OperServ\Event\OperRoleForcedVhostChangedEvent;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordReceivedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncCompleteEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber\UdbNickSyncSubscriber;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbRecordWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function sprintf;

#[CoversClass(UdbNickSyncSubscriber::class)]
final class UdbNickSyncSubscriberTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    private function createSubscriber(
        ?RegisteredNickRepositoryInterface $repo = null,
        ?PasswordMigrationStateInterface $migrationState = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
        string $protocol = 'unrealudb',
    ): UdbNickSyncSubscriber {
        $holder = $this->createConnectedHolder($protocol);

        return new UdbNickSyncSubscriber(
            $holder,
            new UnrealUdbRecordWriter($holder),
            $repo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $migrationState ?? $this->createStub(PasswordMigrationStateInterface::class),
            $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
        );
    }

    private function createConnectedHolder(string $protocol): ActiveConnectionHolder
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $connection->method('writeLine')->willReturnCallback(function (string $line): void {
            $this->written[] = $line;
        });
        $connection->method('isConnected')->willReturn(true);

        $holder = new ActiveConnectionHolder();
        $reflection = new ReflectionClass($holder);
        $property = $reflection->getProperty('connection');
        $property->setValue($holder, $connection);
        $sidProperty = $reflection->getProperty('serverSid');
        $sidProperty->setValue($holder, '001');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getProtocolName')->willReturn($protocol);
        $holder->setProtocolModule($module);

        return $holder;
    }

    public function testGetSubscribedEvents(): void
    {
        $events = UdbNickSyncSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(NickPasswordProvidedEvent::class, $events);
        $this->assertArrayHasKey(NickVhostChangedEvent::class, $events);
        $this->assertArrayHasKey(NickDropEvent::class, $events);
        $this->assertArrayHasKey(UdbSyncRequestedEvent::class, $events);
        $this->assertArrayHasKey(UdbSyncCompleteEvent::class, $events);
        $this->assertArrayHasKey(UdbRecordReceivedEvent::class, $events);
        $this->assertArrayHasKey(OperRoleForcedVhostChangedEvent::class, $events);
        $this->assertArrayHasKey(OperIrcopChangedEvent::class, $events);
    }

    public function testOnNickDropDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbNickSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordMigrationStateInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
        );

        $sub->onNickDrop(new NickDropEvent(1, 'nick', 'User', '127.0.0.1'));

        self::assertSame([], $this->written);
    }

    public function testOnNickDropDoesNothingWhenProtocolIsNotUdb(): void
    {
        $sub = $this->createSubscriber(protocol: 'unreal');

        $sub->onNickDrop(new NickDropEvent(1, 'nick', 'User', '127.0.0.1'));

        self::assertSame([], $this->written);
    }

    public function testOnNickDropSendsDelete(): void
    {
        $sub = $this->createSubscriber();
        $sub->onNickDrop(new NickDropEvent(1, 'nick', 'User', '127.0.0.1'));

        self::assertSame([':001 DB * DEL N::nick'], $this->written);
    }

    public function testOnPasswordProvidedDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbNickSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordMigrationStateInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
        );

        $sub->onPasswordProvided(new NickPasswordProvidedEvent(1, 'nick', 'pass'));

        self::assertSame([], $this->written);
    }

    public function testOnPasswordProvidedWithNullNickId(): void
    {
        $hash = hash('sha256', 'pass');
        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('nick');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->never())->method('findById');

        $sub = $this->createSubscriber($repo, $migrationState);
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(null, 'nick', 'pass'));

        self::assertSame([sprintf(':001 DB * INS N::nick::pass :sha256:%s', $hash)], $this->written);
    }

    public function testOnPasswordProvidedWithoutVhost(): void
    {
        $hash = hash('sha256', 'pass');
        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('nick');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $sub = $this->createSubscriber($repo, $migrationState);
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(1, 'nick', 'pass'));

        self::assertSame([sprintf(':001 DB * INS N::nick::pass :sha256:%s', $hash)], $this->written);
    }

    public function testOnPasswordProvidedWithPersonalVhost(): void
    {
        $hash = hash('sha256', 'pass');
        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getId')->willReturn(1);
        $nick->method('getNickname')->willReturn('nick');
        $nick->method('getVhost')->willReturn('myvhost');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $sub = $this->createSubscriber($repo, null, $ircopRepo);
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(1, 'nick', 'pass'));

        self::assertSame([
            sprintf(':001 DB * INS N::nick::pass :sha256:%s', $hash),
            ':001 DB * INS N::nick::vhost :myvhost',
        ], $this->written);
    }

    public function testOnPasswordProvidedWithOperForcedVhostPriorityOverPersonal(): void
    {
        $hash = hash('sha256', 'pass');
        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getId')->willReturn(10);
        $nick->method('getNickname')->willReturn('oper_nick');
        $nick->method('getVhost')->willReturn('personal.vhost');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($nick);

        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn('staff.example.net');

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('oper_nick');

        $sub = $this->createSubscriber($repo, $migrationState, $ircopRepo);
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(10, 'oper_nick', 'pass'));

        self::assertSame([
            sprintf(':001 DB * INS N::oper_nick::pass :sha256:%s', $hash),
            ':001 DB * INS N::oper_nick::vhost :opernick.staff.example.net',
        ], $this->written);
    }

    public function testOnVhostChangedDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbNickSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordMigrationStateInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
        );

        $sub->onVhostChanged(new NickVhostChangedEvent(1, 'nick', 'new.vhost'));

        self::assertSame([], $this->written);
    }

    public function testOnVhostChangedAccountNotFound(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $sub = $this->createSubscriber($repo);
        $sub->onVhostChanged(new NickVhostChangedEvent(1, 'nick', 'new.vhost'));

        self::assertSame([], $this->written);
    }

    public function testOnVhostChangedWithPersonalVhost(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('nick');
        $account->method('getVhost')->willReturn('personal.vhost.net');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($account);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $sub = $this->createSubscriber($repo, null, $ircopRepo);
        $sub->onVhostChanged(new NickVhostChangedEvent(1, 'nick', 'personal.vhost.net'));

        self::assertSame([':001 DB * INS N::nick::vhost :personal.vhost.net'], $this->written);
    }

    public function testOnVhostChangedWithForcedOperVhost(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(5);
        $account->method('getNickname')->willReturn('admin');
        $account->method('getVhost')->willReturn('custom.vhost');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($account);

        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn('admin.net');

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $sub = $this->createSubscriber($repo, null, $ircopRepo);
        $sub->onVhostChanged(new NickVhostChangedEvent(5, 'admin', null));

        self::assertSame([':001 DB * INS N::admin::vhost :admin.admin.net'], $this->written);
    }

    public function testOnVhostChangedClearedSendsDel(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('nick');
        $account->method('getVhost')->willReturn(null);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($account);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $sub = $this->createSubscriber($repo, null, $ircopRepo);
        $sub->onVhostChanged(new NickVhostChangedEvent(1, 'nick', null));

        self::assertSame([':001 DB * DEL N::nick::vhost'], $this->written);
    }

    public function testOnSyncRequestedDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbNickSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordMigrationStateInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
        );

        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));

        self::assertSame([], $this->written);
    }

    public function testOnSyncRequestedWrongBlock(): void
    {
        $sub = $this->createSubscriber();
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));

        self::assertSame([], $this->written);
    }

    public function testOnSyncRequestedCorrectBlockSendsUnicastRes(): void
    {
        $sub = $this->createSubscriber();
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', 'ABC'));

        self::assertSame([':001 DB ABC RES N'], $this->written);
    }

    public function testOnSyncCompleteDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbNickSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordMigrationStateInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
        );

        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([], $this->written);
    }

    public function testOnSyncCompleteWrongBlock(): void
    {
        $sub = $this->createSubscriber();
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('C', '001'));

        self::assertSame([':001 DB 001 RES N'], $this->written);
    }

    public function testOnSyncCompleteWithoutSyncRequest(): void
    {
        $sub = $this->createSubscriber();
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([], $this->written);
    }

    public function testOnSyncCompleteRepopulatesAllNicks(): void
    {
        $nick1 = $this->createStub(RegisteredNick::class);
        $nick1->method('getId')->willReturn(1);
        $nick1->method('getNickname')->willReturn('alice');
        $nick1->method('getPasswordHash')->willReturn('$2y$hash1');
        $nick1->method('getVhost')->willReturn('alice.vhost.net');

        $nick2 = $this->createStub(RegisteredNick::class);
        $nick2->method('getId')->willReturn(2);
        $nick2->method('getNickname')->willReturn('argon');
        $nick2->method('getPasswordHash')->willReturn('argon2id:$argon2id$valid');
        $nick2->method('getVhost')->willReturn(null);

        $nick3 = $this->createStub(RegisteredNick::class);
        $nick3->method('getId')->willReturn(3);
        $nick3->method('getNickname')->willReturn('crypt');
        $nick3->method('getPasswordHash')->willReturn('crypt:$6$valid');
        $nick3->method('getVhost')->willReturn(null);

        $nick4 = $this->createStub(RegisteredNick::class);
        $nick4->method('getId')->willReturn(4);
        $nick4->method('getNickname')->willReturn('sha');
        $nick4->method('getPasswordHash')->willReturn(sprintf('sha256:%s', str_repeat('a', 64)));
        $nick4->method('getVhost')->willReturn(null);

        $nick5 = $this->createStub(RegisteredNick::class);
        $nick5->method('getId')->willReturn(5);
        $nick5->method('getNickname')->willReturn('empty-crypt');
        $nick5->method('getPasswordHash')->willReturn('crypt:');
        $nick5->method('getVhost')->willReturn(null);

        $nick6 = $this->createStub(RegisteredNick::class);
        $nick6->method('getId')->willReturn(6);
        $nick6->method('getNickname')->willReturn('invalid-sha');
        $nick6->method('getPasswordHash')->willReturn('sha256:not-a-hash');
        $nick6->method('getVhost')->willReturn(null);

        $nick7 = $this->createStub(RegisteredNick::class);
        $nick7->method('getId')->willReturn(7);
        $nick7->method('getNickname')->willReturn('no-password');
        $nick7->method('getPasswordHash')->willReturn(null);
        $nick7->method('getVhost')->willReturn(null);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('all')->willReturn([$nick1, $nick2, $nick3, $nick4, $nick5, $nick6, $nick7]);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $sub = $this->createSubscriber($repo, null, $ircopRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([
            ':001 DB 001 RES N',
            ':001 DB * INS N::alice::vhost :alice.vhost.net',
            ':001 DB * INS N::argon::pass :argon2id:$argon2id$valid',
            ':001 DB * INS N::crypt::pass :crypt:$6$valid',
            sprintf(':001 DB * INS N::sha::pass :sha256:%s', str_repeat('a', 64)),
        ], $this->written);
    }

    public function testOnSyncCompleteWithOperForcedVhost(): void
    {
        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getId')->willReturn(10);
        $nick->method('getNickname')->willReturn('oper_nick');
        $nick->method('getPasswordHash')->willReturn('$2y$hashoper');
        $nick->method('getVhost')->willReturn('personal.vhost');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('all')->willReturn([$nick]);

        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn('staff.example.net');

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $sub = $this->createSubscriber($repo, null, $ircopRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([
            ':001 DB 001 RES N',
            ':001 DB * INS N::oper_nick::vhost :opernick.staff.example.net',
        ], $this->written);
    }

    public function testOnSyncCompleteDoesNotReinsertMatchingUdbRecords(): void
    {
        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getId')->willReturn(1);
        $nick->method('getNickname')->willReturn('alice');
        $nick->method('getPasswordHash')->willReturn(sprintf('sha256:%s', str_repeat('a', 64)));
        $nick->method('getVhost')->willReturn(null);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('all')->willReturn([$nick]);
        $repo->method('findByNick')->willReturn($nick);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('alice');

        $sub = $this->createSubscriber($repo, $migrationState);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::alice::pass', sprintf('sha256:%s', str_repeat('a', 64))));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N'], $this->written);
    }

    public function testOnRecordReceivedDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbNickSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordMigrationStateInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
        );

        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::nick::pass', 'hash'));

        self::assertSame([], $this->written);
    }

    public function testOnRecordReceivedWrongBlockOrMalformed(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->never())->method('findByNick');

        $sub = $this->createSubscriber($repo);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('C::chan::founder', 'nick'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::malformed', 'val'));

        self::assertSame([], $this->written);
    }

    public function testOnRecordReceivedNickNotFoundSendsDel(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('ghostnick')->willReturn(null);
        $repo->method('all')->willReturn([]);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->never())->method('markAsMigrated');

        $sub = $this->createSubscriber($repo, $migrationState);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::ghostnick::pass', 'hash'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N', ':001 DB * DEL N::ghostnick'], $this->written);
    }

    public function testOnRecordReceivedPassRecordWithMatchingServicePassword(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getVhost')->willReturn('davidlig.vhost.net');
        $account->method('getPasswordHash')->willReturn(sprintf('sha256:%s', str_repeat('a', 64)));

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);
        $repo->method('all')->willReturn([]);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('davidlig');

        $sub = $this->createSubscriber($repo, $migrationState);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::pass', sprintf('sha256:%s', str_repeat('a', 64))));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N'], $this->written);
    }

    public function testOnRecordReceivedPassRecordWithoutServicePasswordDeletesIt(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getPasswordHash')->willReturn(null);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);
        $repo->method('all')->willReturn([]);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('davidlig');

        $sub = $this->createSubscriber($repo, $migrationState);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::pass', 'sha256:abc'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N', ':001 DB * DEL N::davidlig::pass'], $this->written);
    }

    public function testOnRecordReceivedPassRecordWithDifferentServicePasswordReplacesIt(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getPasswordHash')->willReturn(sprintf('sha256:%s', str_repeat('a', 64)));

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);
        $repo->method('all')->willReturn([]);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('davidlig');

        $sub = $this->createSubscriber($repo, $migrationState);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::pass', sprintf('sha256:%s', str_repeat('b', 64))));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([
            ':001 DB 001 RES N',
            sprintf(':001 DB * INS N::davidlig::pass :sha256:%s', str_repeat('a', 64)),
        ], $this->written);
    }

    public function testOnRecordReceivedBcryptPasswordPreservesValidUdbPassword(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getPasswordHash')->willReturn('$2y$12$local-bcrypt-hash');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);
        $repo->method('all')->willReturn([]);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('davidlig');

        $sub = $this->createSubscriber($repo, $migrationState);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent(
            'N::davidlig::pass',
            sprintf('sha256:%s', str_repeat('a', 64)),
        ));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N'], $this->written);
    }

    public function testOnRecordReceivedVhostRecordWhenNoEffectiveVhostSendsDel(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getVhost')->willReturn(null);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);
        $repo->method('all')->willReturn([]);

        $sub = $this->createSubscriber($repo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::vhost', 'old.vhost.net'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N', ':001 DB * DEL N::davidlig::vhost'], $this->written);
    }

    public function testOnRecordReceivedVhostRecordWhenMatchesEffectiveVhostDoesNothing(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getVhost')->willReturn('exact.vhost.net');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);
        $repo->method('all')->willReturn([]);

        $sub = $this->createSubscriber($repo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::vhost', 'exact.vhost.net'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N'], $this->written);
    }

    public function testOnRecordReceivedVhostRecordWhenDiffersFromEffectiveVhostSendsIns(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getVhost')->willReturn('expected.vhost.net');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);
        $repo->method('all')->willReturn([]);

        $sub = $this->createSubscriber($repo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::vhost', 'outdated.vhost.net'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N', ':001 DB * INS N::davidlig::vhost :expected.vhost.net'], $this->written);
    }

    public function testOnRecordReceivedOperWhenNotIrcopSendsDel(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);
        $repo->method('all')->willReturn([]);

        $sub = $this->createSubscriber($repo, null, $ircopRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::oper', '*2'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N', ':001 DB * DEL N::davidlig::oper'], $this->written);
    }

    public function testOnRecordReceivedOperWhenIsIrcopDoesNothing(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);

        $ircop = $this->createStub(OperIrcop::class);
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);
        $repo->method('all')->willReturn([]);

        $sub = $this->createSubscriber($repo, null, $ircopRepo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::oper', '*2'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N'], $this->written);
    }

    public function testOnRecordReceivedSwhoisSendsDel(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);
        $repo->method('all')->willReturn([]);

        $sub = $this->createSubscriber($repo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::swhois', 'Some whois line'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N', ':001 DB * DEL N::davidlig::swhois'], $this->written);
    }

    public function testOnRecordReceivedUnknownPropertySendsDel(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);
        $repo->method('all')->willReturn([]);

        $sub = $this->createSubscriber($repo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::email', 'david@example.com'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N', ':001 DB * DEL N::davidlig::email'], $this->written);
    }

    public function testOnRecordReceivedOutsideSyncDoesNothing(): void
    {
        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->never())->method('findByNick');

        $sub = $this->createSubscriber($repo);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::ghostnick::pass', 'hash'));

        self::assertSame([], $this->written);
    }

    public function testOnNickDropDuringSyncIsBufferedUntilComplete(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('all')->willReturn([]);

        $sub = $this->createSubscriber($repo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onNickDrop(new NickDropEvent(1, 'nick', 'User', '127.0.0.1'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        self::assertSame([':001 DB 001 RES N', ':001 DB * DEL N::nick'], $this->written);
    }

    public function testOnPasswordProvidedDuringSyncIsBufferedUntilComplete(): void
    {
        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $sub = $this->createSubscriber($repo);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(1, 'nick', 'pass'));
        $sub->onSyncComplete(new UdbSyncCompleteEvent('N', '001'));

        $hash = hash('sha256', 'pass');
        self::assertSame([
            ':001 DB 001 RES N',
            sprintf(':001 DB * INS N::nick::pass :sha256:%s', $hash),
        ], $this->written);
    }

    public function testResolveEffectiveVhostFallbacks(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('test');
        $account->method('getVhost')->willReturn('personal.net');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($account);

        // 1. Role with null pattern falls back to personal vhost
        $role1 = $this->createStub(OperRole::class);
        $role1->method('getForcedVhostPattern')->willReturn(null);
        $ircop1 = $this->createStub(OperIrcop::class);
        $ircop1->method('getRole')->willReturn($role1);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop1);

        $this->written = [];
        $sub = $this->createSubscriber($repo, null, $ircopRepo);
        $sub->onVhostChanged(new NickVhostChangedEvent(1, 'test', 'personal.net'));
        self::assertSame([':001 DB * INS N::test::vhost :personal.net'], $this->written);

        // 2. Role with invalid pattern falls back to personal vhost
        $role2 = $this->createStub(OperRole::class);
        $role2->method('getForcedVhostPattern')->willReturn('invalid..pattern');
        $ircop2 = $this->createStub(OperIrcop::class);
        $ircop2->method('getRole')->willReturn($role2);

        $ircopRepo2 = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo2->method('findByNickId')->willReturn($ircop2);

        $this->written = [];
        $sub2 = $this->createSubscriber($repo, null, $ircopRepo2);
        $sub2->onVhostChanged(new NickVhostChangedEvent(1, 'test', 'personal.net'));
        self::assertSame([':001 DB * INS N::test::vhost :personal.net'], $this->written);

        // 3. Personal vhost with only spaces is treated as null -> DB * DEL
        $account3 = $this->createStub(RegisteredNick::class);
        $account3->method('getId')->willReturn(3);
        $account3->method('getNickname')->willReturn('test3');
        $account3->method('getVhost')->willReturn('   ');

        $repo3 = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo3->method('findById')->willReturn($account3);

        $ircopRepo3 = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo3->method('findByNickId')->willReturn(null);

        $this->written = [];
        $sub3 = $this->createSubscriber($repo3, null, $ircopRepo3);
        $sub3->onVhostChanged(new NickVhostChangedEvent(3, 'test3', '   '));
        self::assertSame([':001 DB * DEL N::test3::vhost'], $this->written);
    }

    public function testOnOperRoleForcedVhostChangedDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbNickSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordMigrationStateInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
        );

        $sub->onOperRoleForcedVhostChanged(new OperRoleForcedVhostChangedEvent(1, 'staff.example.net'));

        self::assertSame([], $this->written);
    }

    public function testOnOperRoleForcedVhostChangedWithMultipleIrcops(): void
    {
        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn('staff.example.net');

        $ircop1 = $this->createStub(OperIrcop::class);
        $ircop1->method('getNickId')->willReturn(10);
        $ircop1->method('getRole')->willReturn($role);

        $ircop2 = $this->createStub(OperIrcop::class);
        $ircop2->method('getNickId')->willReturn(20);

        $roleNoVhost = $this->createStub(OperRole::class);
        $roleNoVhost->method('getForcedVhostPattern')->willReturn(null);

        $ircop3 = $this->createStub(OperIrcop::class);
        $ircop3->method('getNickId')->willReturn(30);
        $ircop3->method('getRole')->willReturn($roleNoVhost);

        $account1 = $this->createStub(RegisteredNick::class);
        $account1->method('getId')->willReturn(10);
        $account1->method('getNickname')->willReturn('oper1');
        $account1->method('getVhost')->willReturn(null);

        $account3 = $this->createStub(RegisteredNick::class);
        $account3->method('getId')->willReturn(30);
        $account3->method('getNickname')->willReturn('oper3');
        $account3->method('getVhost')->willReturn(null);

        $ircopRepo = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepo->expects($this->once())->method('findByRoleId')->with(1)->willReturn([$ircop1, $ircop2, $ircop3]);
        $ircopRepo->method('findByNickId')->willReturnCallback(static function (int $nickId) use ($ircop1, $ircop3): ?OperIrcop {
            if (10 === $nickId) {
                return $ircop1;
            }
            if (30 === $nickId) {
                return $ircop3;
            }

            return null;
        });

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturnCallback(static function (int $id) use ($account1, $account3): ?RegisteredNick {
            if (10 === $id) {
                return $account1;
            }
            if (30 === $id) {
                return $account3;
            }

            return null;
        });

        $sub = $this->createSubscriber($nickRepo, null, $ircopRepo);
        $sub->onOperRoleForcedVhostChanged(new OperRoleForcedVhostChangedEvent(1, 'staff.example.net'));

        self::assertSame([
            ':001 DB * INS N::oper1::vhost :oper1.staff.example.net',
            ':001 DB * DEL N::oper3::vhost',
        ], $this->written);
    }

    public function testOnOperIrcopChangedDoesNothingWhenNotConnected(): void
    {
        $sub = new UdbNickSyncSubscriber(
            new ActiveConnectionHolder(),
            $this->createStub(UdbRecordWriterInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $this->createStub(PasswordMigrationStateInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
        );

        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(1, 'testnick'));

        self::assertSame([], $this->written);
    }

    public function testOnOperIrcopChangedAccountNotFound(): void
    {
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $sub = $this->createSubscriber($nickRepo);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(1, 'testnick'));

        self::assertSame([], $this->written);
    }

    public function testOnOperIrcopChangedWithEffectiveVhost(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(5);
        $account->method('getNickname')->willReturn('admin');
        $account->method('getVhost')->willReturn('custom.vhost');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($account);

        $role = $this->createStub(OperRole::class);
        $role->method('getForcedVhostPattern')->willReturn('admin.net');

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $sub = $this->createSubscriber($nickRepo, null, $ircopRepo);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(5, 'admin'));

        self::assertSame([':001 DB * INS N::admin::vhost :admin.admin.net'], $this->written);
    }

    public function testOnOperIrcopChangedWithoutEffectiveVhostSendsDel(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(7);
        $account->method('getNickname')->willReturn('former_oper');
        $account->method('getVhost')->willReturn(null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($account);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $sub = $this->createSubscriber($nickRepo, null, $ircopRepo);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(7, 'former_oper'));

        self::assertSame([':001 DB * DEL N::former_oper::vhost'], $this->written);
    }
}
