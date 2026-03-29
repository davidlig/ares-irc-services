<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\ForcedVhostApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ForcedVhostApplier::class)]
final class ForcedVhostApplierTest extends TestCase
{
    #[Test]
    public function applyForcedVhostReturnsFalseWhenNotIrcop(): void
    {
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn(null);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $result = $applier->applyForcedVhostIfApplicable(123, 'TestNick', 'UID1');

        self::assertFalse($result);
    }

    #[Test]
    public function applyForcedVhostReturnsFalseWhenRoleHasNoPattern(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $result = $applier->applyForcedVhostIfApplicable(123, 'TestNick', 'UID1');

        self::assertFalse($result);
    }

    #[Test]
    public function applyForcedVhostAppliesVhostWhenRoleHasPattern(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $role->setForcedVhostPattern('admin.network');
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', 'davidlig.admin.network', '001');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $result = $applier->applyForcedVhostIfApplicable(123, '_davidlig_', 'UID1');

        self::assertTrue($result);
    }

    #[Test]
    public function applyForcedVhostCleansNickname(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $role->setForcedVhostPattern('staff.ares');
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', 'UserTest.staff.ares', '001');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $result = $applier->applyForcedVhostIfApplicable(123, '|User|Test|', 'UID1');

        self::assertTrue($result);
    }

    #[Test]
    public function updateVhostForRoleDoesNothingWhenNoIrcops(): void
    {
        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByRoleId')->willReturn([]);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $applier->updateVhostForRole(1, 'admin.ares');
    }

    #[Test]
    public function updateVhostForRoleAppliesVhostToIdentifiedIrcops(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $role->setForcedVhostPattern('admin.network');
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByRoleId')->willReturn([$ircop]);

        $nick = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $nick->method('getId')->willReturn(123);
        $nick->method('getNickname')->willReturn('davidlig');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'davidlig');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', 'davidlig.admin.network', '001');

        $user = new SenderView('UID1', 'davidlig', 'i', 'h', 'c', 'ip');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($user);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $applier->updateVhostForRole(1, 'admin.network');
    }

    #[Test]
    public function updateVhostForRoleClearsVhostWhenPatternIsNull(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByRoleId')->willReturn([$ircop]);

        $nick = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $nick->method('getId')->willReturn(123);
        $nick->method('getNickname')->willReturn('davidlig');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'davidlig');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('setUserVhost')->with('UID1', '', '001');

        $user = new SenderView('UID1', 'davidlig', 'i', 'h', 'c', 'ip');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($user);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getServerSid')->willReturn('001');

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $applier->updateVhostForRole(1, null);
    }

    #[Test]
    public function updateVhostForRoleSkipsNotIdentifiedIrcops(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByRoleId')->willReturn([$ircop]);

        $nick = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $nick->method('getId')->willReturn(123);
        $nick->method('getNickname')->willReturn('davidlig');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        $identifiedRegistry = new IdentifiedSessionRegistry();

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $applier->updateVhostForRole(1, 'admin.network');
    }

    #[Test]
    public function applyForcedVhostReturnsFalseWhenPatternEmpty(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $role->setForcedVhostPattern('');
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $result = $applier->applyForcedVhostIfApplicable(123, 'TestNick', 'UID1');

        self::assertFalse($result);
    }

    #[Test]
    public function applyForcedVhostReturnsFalseWhenPatternInvalid(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $role->setForcedVhostPattern('invalid-pattern-no-dot');
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByNickId')->willReturn($ircop);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $warnings = [];

            public function log($level, $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->warnings[] = ['message' => $message, 'context' => $context];
                }
            }
        };

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            $logger,
        );

        $result = $applier->applyForcedVhostIfApplicable(123, 'TestNick', 'UID1');

        self::assertFalse($result);
        self::assertCount(1, $logger->warnings);
        self::assertSame('ForcedVhostApplier: invalid pattern stored for role', $logger->warnings[0]['message']);
    }

    #[Test]
    public function updateVhostForRoleSkipsWhenNickNotFound(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByRoleId')->willReturn([$ircop]);

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn(null);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $applier->updateVhostForRole(1, 'admin.network');
    }

    #[Test]
    public function updateVhostForRoleSkipsWhenUserNotFound(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByRoleId')->willReturn([$ircop]);

        $nick = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $nick->method('getId')->willReturn(123);
        $nick->method('getNickname')->willReturn('davidlig');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'davidlig');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            new NullLogger(),
        );

        $applier->updateVhostForRole(1, 'admin.network');
    }

    #[Test]
    public function updateVhostForRoleLogsWarningWhenPatternInvalid(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role', true);
        $ircop = OperIrcop::create(123, $role);

        $ircopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepo->method('findByRoleId')->willReturn([$ircop]);

        $nick = $this->createStub(\App\Domain\NickServ\Entity\RegisteredNick::class);
        $nick->method('getId')->willReturn(123);
        $nick->method('getNickname')->willReturn('davidlig');

        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findById')->willReturn($nick);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID1', 'davidlig');

        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::never())->method('setUserVhost');

        $user = new SenderView('UID1', 'davidlig', 'i', 'h', 'c', 'ip');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn($user);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);

        $logger = new class extends \Psr\Log\AbstractLogger {
            public array $warnings = [];

            public function log($level, $message, array $context = []): void
            {
                if ('warning' === $level) {
                    $this->warnings[] = ['message' => $message, 'context' => $context];
                }
            }
        };

        $applier = new ForcedVhostApplier(
            $ircopRepo,
            $nickRepo,
            $identifiedRegistry,
            $notifier,
            $userLookup,
            $connectionHolder,
            $logger,
        );

        $applier->updateVhostForRole(1, 'invalid-pattern-no-dot');

        self::assertCount(1, $logger->warnings);
        self::assertSame('ForcedVhostApplier: invalid pattern for role update', $logger->warnings[0]['message']);
    }
}
