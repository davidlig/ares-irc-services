<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\PasswordMigrationStateInterface;
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
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbRecordReceivedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber\UdbNickSyncSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbNickSyncSubscriber::class)]
final class UdbNickSyncSubscriberTest extends TestCase
{
    private function createSubscriber(
        ?ActiveConnectionHolderInterface $holder = null,
        ?RegisteredNickRepositoryInterface $repo = null,
        ?PasswordMigrationStateInterface $migrationState = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
    ): UdbNickSyncSubscriber {
        return new UdbNickSyncSubscriber(
            $holder ?? $this->createStub(ActiveConnectionHolderInterface::class),
            $repo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
            $migrationState ?? $this->createStub(PasswordMigrationStateInterface::class),
            $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
        );
    }

    public function testGetSubscribedEvents(): void
    {
        $events = UdbNickSyncSubscriber::getSubscribedEvents();
        $this->assertArrayHasKey(NickPasswordProvidedEvent::class, $events);
        $this->assertArrayHasKey(NickVhostChangedEvent::class, $events);
        $this->assertArrayHasKey(NickDropEvent::class, $events);
        $this->assertArrayHasKey(UdbSyncRequestedEvent::class, $events);
        $this->assertArrayHasKey(UdbRecordReceivedEvent::class, $events);
        $this->assertArrayHasKey(OperRoleForcedVhostChangedEvent::class, $events);
        $this->assertArrayHasKey(OperIrcopChangedEvent::class, $events);
    }

    public function testOnNickDropNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(false);
        $holder->expects($this->never())->method('writeLine');

        $sub = $this->createSubscriber($holder);
        $sub->onNickDrop(new NickDropEvent(1, 'nick', 'User', '127.0.0.1'));
    }

    public function testOnNickDropConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * DEL N::nick');

        $sub = $this->createSubscriber($holder);
        $sub->onNickDrop(new NickDropEvent(1, 'nick', 'User', '127.0.0.1'));
    }

    public function testOnPasswordProvidedNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(false);

        $sub = $this->createSubscriber($holder);
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(1, 'nick', 'pass'));
    }

    public function testOnPasswordProvidedWithNullNickId(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $hash = hash('sha256', 'pass');
        $holder->expects($this->once())->method('writeLine')
            ->with("DB * INS N::nick::pass sha256:{$hash}");

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->never())->method('findById');

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('nick');

        $sub = $this->createSubscriber($holder, $repo, $migrationState);
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(null, 'nick', 'pass'));
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

    public function testOnPasswordProvidedWithPersonalVhost(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $hash = hash('sha256', 'pass');
        $holder->expects($this->exactly(2))->method('writeLine')->willReturnCallback(function (string $line) use ($hash): void {
            static $call = 0;
            ++$call;
            if (1 === $call) {
                $this->assertSame("DB * INS N::nick::pass sha256:{$hash}", $line);
            } else {
                $this->assertSame('DB * INS N::nick::vhost myvhost', $line);
            }
        });

        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getId')->willReturn(1);
        $nick->method('getNickname')->willReturn('nick');
        $nick->method('getVhost')->willReturn('myvhost');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($nick);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('nick');

        $sub = $this->createSubscriber($holder, $repo, $migrationState, $ircopRepo);
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(1, 'nick', 'pass'));
    }

    public function testOnPasswordProvidedWithOperForcedVhostPriorityOverPersonal(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $hash = hash('sha256', 'pass');
        $holder->expects($this->exactly(2))->method('writeLine')->willReturnCallback(function (string $line) use ($hash): void {
            static $call = 0;
            ++$call;
            if (1 === $call) {
                $this->assertSame("DB * INS N::oper_nick::pass sha256:{$hash}", $line);
            } else {
                $this->assertSame('DB * INS N::oper_nick::vhost opernick.staff.example.net', $line);
            }
        });

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

        $sub = $this->createSubscriber($holder, $repo, $migrationState, $ircopRepo);
        $sub->onPasswordProvided(new NickPasswordProvidedEvent(10, 'oper_nick', 'pass'));
    }

    public function testOnVhostChangedNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(false);
        $holder->expects($this->never())->method('writeLine');

        $sub = $this->createSubscriber($holder);
        $sub->onVhostChanged(new NickVhostChangedEvent(1, 'nick', 'new.vhost'));
    }

    public function testOnVhostChangedAccountNotFound(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);

        $sub = $this->createSubscriber($holder, $repo);
        $sub->onVhostChanged(new NickVhostChangedEvent(1, 'nick', 'new.vhost'));
    }

    public function testOnVhostChangedWithPersonalVhost(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * INS N::nick::vhost personal.vhost.net');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('nick');
        $account->method('getVhost')->willReturn('personal.vhost.net');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($account);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $sub = $this->createSubscriber($holder, $repo, null, $ircopRepo);
        $sub->onVhostChanged(new NickVhostChangedEvent(1, 'nick', 'personal.vhost.net'));
    }

    public function testOnVhostChangedWithForcedOperVhost(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * INS N::admin::vhost admin.admin.net');

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

        $sub = $this->createSubscriber($holder, $repo, null, $ircopRepo);
        $sub->onVhostChanged(new NickVhostChangedEvent(5, 'admin', null));
    }

    public function testOnVhostChangedClearedSendsDel(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * DEL N::nick::vhost');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('nick');
        $account->method('getVhost')->willReturn(null);

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($account);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $sub = $this->createSubscriber($holder, $repo, null, $ircopRepo);
        $sub->onVhostChanged(new NickVhostChangedEvent(1, 'nick', null));
    }

    public function testOnSyncRequestedNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(false);

        $sub = $this->createSubscriber($holder);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
    }

    public function testOnSyncRequestedWrongBlock(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $sub = $this->createSubscriber($holder);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('C', '001'));
    }

    public function testOnSyncRequestedCorrectBlock(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * REQ N');

        $sub = $this->createSubscriber($holder);
        $sub->onSyncRequested(new UdbSyncRequestedEvent('N', '001'));
    }

    public function testOnRecordReceivedNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(false);

        $sub = $this->createSubscriber($holder);
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

    public function testOnRecordReceivedNickNotFoundSendsDel(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * DEL N::ghostnick');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('ghostnick')->willReturn(null);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->never())->method('markAsMigrated');

        $sub = $this->createSubscriber($holder, $repo, $migrationState);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::ghostnick::pass', 'hash'));
    }

    public function testOnRecordReceivedPassRecordWithEffectiveVhost(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * INS N::davidlig::vhost davidlig.vhost.net');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getVhost')->willReturn('davidlig.vhost.net');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('davidlig');

        $sub = $this->createSubscriber($holder, $repo, $migrationState);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::pass', 'sha256:abc'));
    }

    public function testOnRecordReceivedPassRecordWithoutEffectiveVhost(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getVhost')->willReturn(null);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);

        $migrationState = $this->createMock(PasswordMigrationStateInterface::class);
        $migrationState->expects($this->once())->method('markAsMigrated')->with('davidlig');

        $sub = $this->createSubscriber($holder, $repo, $migrationState);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::pass', 'sha256:abc'));
    }

    public function testOnRecordReceivedVhostRecordWhenNoEffectiveVhostSendsDel(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * DEL N::davidlig::vhost');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getVhost')->willReturn(null);

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);

        $sub = $this->createSubscriber($holder, $repo);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::vhost', 'old.vhost.net'));
    }

    public function testOnRecordReceivedVhostRecordWhenMatchesEffectiveVhostDoesNothing(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getVhost')->willReturn('exact.vhost.net');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);

        $sub = $this->createSubscriber($holder, $repo);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::vhost', 'exact.vhost.net'));
    }

    public function testOnRecordReceivedVhostRecordWhenDiffersFromEffectiveVhostSendsIns(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * INS N::davidlig::vhost expected.vhost.net');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');
        $account->method('getVhost')->willReturn('expected.vhost.net');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);

        $sub = $this->createSubscriber($holder, $repo);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::vhost', 'outdated.vhost.net'));
    }

    public function testOnRecordReceivedUnknownPropertyDoesNothing(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('davidlig');

        $repo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $repo->expects($this->once())->method('findByNick')->with('davidlig')->willReturn($account);

        $sub = $this->createSubscriber($holder, $repo);
        $sub->onRecordReceived(new UdbRecordReceivedEvent('N::davidlig::email', 'david@example.com'));
    }

    public function testResolveEffectiveVhostFallbacks(): void
    {
        // 1. Role with null pattern falls back to personal vhost
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);
        $account->method('getNickname')->willReturn('test');
        $account->method('getVhost')->willReturn('personal.net');

        $role1 = $this->createStub(OperRole::class);
        $role1->method('getForcedVhostPattern')->willReturn(null);
        $ircop1 = $this->createStub(OperIrcop::class);
        $ircop1->method('getRole')->willReturn($role1);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop1);

        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * INS N::test::vhost personal.net');

        $repo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo->method('findById')->willReturn($account);

        $sub = $this->createSubscriber($holder, $repo, null, $ircopRepo);
        $sub->onVhostChanged(new NickVhostChangedEvent(1, 'test', 'personal.net'));

        // 2. Role with invalid pattern falls back to personal vhost
        $role2 = $this->createStub(OperRole::class);
        $role2->method('getForcedVhostPattern')->willReturn('invalid..pattern');
        $ircop2 = $this->createStub(OperIrcop::class);
        $ircop2->method('getRole')->willReturn($role2);

        $ircopRepo2 = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo2->method('findByNickId')->willReturn($ircop2);

        $holder2 = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder2->expects($this->once())->method('isConnected')->willReturn(true);
        $holder2->expects($this->once())->method('writeLine')->with('DB * INS N::test::vhost personal.net');

        $sub2 = $this->createSubscriber($holder2, $repo, null, $ircopRepo2);
        $sub2->onVhostChanged(new NickVhostChangedEvent(1, 'test', 'personal.net'));

        // 3. Personal vhost with only spaces is treated as null -> DB * DEL
        $account3 = $this->createStub(RegisteredNick::class);
        $account3->method('getId')->willReturn(3);
        $account3->method('getNickname')->willReturn('test3');
        $account3->method('getVhost')->willReturn('   ');

        $repo3 = $this->createStub(RegisteredNickRepositoryInterface::class);
        $repo3->method('findById')->willReturn($account3);

        $holder3 = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder3->expects($this->once())->method('isConnected')->willReturn(true);
        $holder3->expects($this->once())->method('writeLine')->with('DB * DEL N::test3::vhost');

        $ircopRepo3 = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo3->method('findByNickId')->willReturn(null);

        $sub3 = $this->createSubscriber($holder3, $repo3, null, $ircopRepo3);
        $sub3->onVhostChanged(new NickVhostChangedEvent(3, 'test3', '   '));
    }

    public function testOnOperRoleForcedVhostChangedNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(false);
        $holder->expects($this->never())->method('writeLine');

        $sub = $this->createSubscriber($holder);
        $sub->onOperRoleForcedVhostChanged(new OperRoleForcedVhostChangedEvent(1, 'staff.example.net'));
    }

    public function testOnOperRoleForcedVhostChangedWithMultipleIrcops(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);

        $writtenLines = [];
        $holder->expects($this->exactly(2))->method('writeLine')->willReturnCallback(static function (string $line) use (&$writtenLines): void {
            $writtenLines[] = $line;
        });

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

        $sub = $this->createSubscriber($holder, $nickRepo, null, $ircopRepo);
        $sub->onOperRoleForcedVhostChanged(new OperRoleForcedVhostChangedEvent(1, 'staff.example.net'));

        $this->assertSame([
            'DB * INS N::oper1::vhost oper1.staff.example.net',
            'DB * DEL N::oper3::vhost',
        ], $writtenLines);
    }

    public function testOnOperIrcopChangedNotConnected(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(false);
        $holder->expects($this->never())->method('writeLine');

        $sub = $this->createSubscriber($holder);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(1, 'testnick'));
    }

    public function testOnOperIrcopChangedAccountNotFound(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->never())->method('writeLine');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $sub = $this->createSubscriber($holder, $nickRepo);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(1, 'testnick'));
    }

    public function testOnOperIrcopChangedWithEffectiveVhost(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * INS N::admin::vhost admin.admin.net');

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

        $sub = $this->createSubscriber($holder, $nickRepo, null, $ircopRepo);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(5, 'admin'));
    }

    public function testOnOperIrcopChangedWithoutEffectiveVhostSendsDel(): void
    {
        $holder = $this->createMock(ActiveConnectionHolderInterface::class);
        $holder->expects($this->once())->method('isConnected')->willReturn(true);
        $holder->expects($this->once())->method('writeLine')->with('DB * DEL N::former_oper::vhost');

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(7);
        $account->method('getNickname')->willReturn('former_oper');
        $account->method('getVhost')->willReturn(null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($account);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $sub = $this->createSubscriber($holder, $nickRepo, null, $ircopRepo);
        $sub->onOperIrcopChanged(new OperIrcopChangedEvent(7, 'former_oper'));
    }
}
