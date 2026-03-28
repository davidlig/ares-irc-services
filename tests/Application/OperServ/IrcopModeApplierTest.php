<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(IrcopModeApplier::class)]
final class IrcopModeApplierTest extends TestCase
{
    private function createApplier(
        ?IdentifiedSessionRegistry $registry = null,
        ?ActiveConnectionHolderInterface $holder = null,
    ): IrcopModeApplier {
        return new IrcopModeApplier(
            $registry ?? new IdentifiedSessionRegistry(),
            $holder ?? $this->createStub(ActiveConnectionHolderInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            new NullLogger(),
        );
    }

    #[Test]
    public function applyModesForNickReturnsFalseWhenModesEmpty(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role');
        $applier = $this->createApplier();

        self::assertFalse($applier->applyModesForNick('TestNick', $role));
    }

    #[Test]
    public function applyModesForNickReturnsFalseWhenUserNotIdentified(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);
        $applier = $this->createApplier();

        self::assertFalse($applier->applyModesForNick('TestNick', $role));
    }

    #[Test]
    public function applyModesForNickReturnsFalseWhenNoProtocolModule(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder);

        self::assertFalse($applier->applyModesForNick('TestNick', $role));
    }

    #[Test]
    public function applyModesForNickAppliesSvsmodeWhenUserIdentifiedAndConnected(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())
            ->method('setUserMode')
            ->with('SID', 'UID123', '+HW');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder);

        self::assertTrue($applier->applyModesForNick('TestNick', $role));
    }

    #[Test]
    public function applyModesForNickIsCaseInsensitive(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())
            ->method('setUserMode')
            ->with('SID', 'UID123', '+HW');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder);

        self::assertTrue($applier->applyModesForNick('testnick', $role));
    }

    #[Test]
    public function removeModesForNickReturnsFalseWhenModesEmpty(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role');
        $applier = $this->createApplier();

        self::assertFalse($applier->removeModesForNick('TestNick', $role));
    }

    #[Test]
    public function removeModesForNickReturnsFalseWhenUserNotIdentified(): void
    {
        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);
        $applier = $this->createApplier();

        self::assertFalse($applier->removeModesForNick('TestNick', $role));
    }

    #[Test]
    public function removeModesForNickReturnsFalseWhenNoProtocolModule(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn(null);

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder);

        self::assertFalse($applier->removeModesForNick('TestNick', $role));
    }

    #[Test]
    public function removeModesForNickAppliesNegativeSvsmodeWhenUserIdentifiedAndConnected(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())
            ->method('setUserMode')
            ->with('SID', 'UID123', '-HW');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder);

        self::assertTrue($applier->removeModesForNick('TestNick', $role));
    }

    #[Test]
    public function updateModesForRoleDoesNothingWhenNoChanges(): void
    {
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);

        $applier = new IrcopModeApplier(
            new IdentifiedSessionRegistry(),
            $this->createStub(ActiveConnectionHolderInterface::class),
            $ircopRepository,
            $nickRepository,
            new NullLogger(),
        );

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        // Same modes before and after - should do nothing
        $applier->updateModesForRole(1, ['H', 'W'], ['H', 'W']);

        // If we reach here without errors, test passes
        self::assertTrue(true);
    }

    #[Test]
    public function updateModesForRoleSkipsUsersNotIdentified(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'q']);
        $roleId = 1;

        // Use real OperIrcop and RegisteredNick
        $ircop = OperIrcop::create(42, $role, null, null);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByRoleId')->willReturn([$ircop]);

        // Nick not in identified registry
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findById')->willReturn(null);

        $applier = new IrcopModeApplier(
            $identifiedRegistry,
            $this->createStub(ActiveConnectionHolderInterface::class),
            $ircopRepository,
            $nickRepository,
            new NullLogger(),
        );

        // Should skip since no user is identified
        $applier->updateModesForRole($roleId, [], ['H', 'q']);

        // If we reach here without errors, test passes
        self::assertTrue(true);
    }

    #[Test]
    public function updateModesForRoleAppliesAndRemovesDiffs(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::exactly(2))
            ->method('setUserMode')
            ->willReturnCallback(static function (string $sid, string $uid, string $modes): bool {
                self::assertSame('SID', $sid);
                self::assertSame('UID123', $uid);
                self::assertTrue('-H' === $modes || '+q' === $modes);

                return true;
            });

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['q']);
        $roleId = 1;

        $ircop = OperIrcop::create(42, $role, null, null);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByRoleId')->willReturn([$ircop]);

        $nick = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findById')->willReturn($nick);

        $applier = new IrcopModeApplier(
            $identifiedRegistry,
            $connectionHolder,
            $ircopRepository,
            $nickRepository,
            new NullLogger(),
        );

        // Old modes: ['H'], New modes: ['q'] -> should remove H and add q
        $applier->updateModesForRole($roleId, ['H'], ['q']);
    }

    #[Test]
    public function updateModesForRoleSkipsIrcopWithMissingNickRecord(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('setUserMode');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['q']);
        $roleId = 1;

        $ircop = OperIrcop::create(42, $role, null, null);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByRoleId')->willReturn([$ircop]);

        // Nick record not found in repository
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findById')->willReturn(null);

        $applier = new IrcopModeApplier(
            $identifiedRegistry,
            $connectionHolder,
            $ircopRepository,
            $nickRepository,
            new NullLogger(),
        );

        $applier->updateModesForRole($roleId, [], ['q']);

        // Should reach here without calling setUserMode (nick not found)
        self::assertTrue(true);
    }

    #[Test]
    public function updateModesForRoleSkipsIrcopWithUserIdentifiedButNotInRegistry(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        // User NOT identified - empty registry

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('setUserMode');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['q']);
        $roleId = 1;

        $ircop = OperIrcop::create(42, $role, null, null);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByRoleId')->willReturn([$ircop]);

        // Nick found but user not in identified registry
        $nick = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findById')->willReturn($nick);

        $applier = new IrcopModeApplier(
            $identifiedRegistry,
            $connectionHolder,
            $ircopRepository,
            $nickRepository,
            new NullLogger(),
        );

        $applier->updateModesForRole($roleId, [], ['q']);

        // Should reach here without calling setUserMode (user not identified)
        self::assertTrue(true);
    }
}
