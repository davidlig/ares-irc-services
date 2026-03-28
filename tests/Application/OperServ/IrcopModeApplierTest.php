<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\SenderView;
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
        ?NetworkUserLookupPort $userLookup = null,
    ): IrcopModeApplier {
        return new IrcopModeApplier(
            $registry ?? new IdentifiedSessionRegistry(),
            $holder ?? $this->createStub(ActiveConnectionHolderInterface::class),
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
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
    public function applyModesForNickReturnsTrueWhenUserAlreadyHasAllModes(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            uid: 'UID123',
            nick: 'TestNick',
            ident: 'test',
            hostname: 'host.test',
            cloakedHost: 'hidden.host',
            ipBase64: 'AAAA',
            isIdentified: true,
            isOper: true,
            serverSid: '001',
            displayHost: 'host.test',
            modes: '+ioHqW',
        ));

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($this->createStub(ProtocolModuleInterface::class));
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder, $userLookup);

        self::assertTrue($applier->applyModesForNick('TestNick', $role));
    }

    #[Test]
    public function applyModesForNickAppliesOnlyMissingModes(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            uid: 'UID123',
            nick: 'TestNick',
            ident: 'test',
            hostname: 'host.test',
            cloakedHost: 'hidden.host',
            ipBase64: 'AAAA',
            isIdentified: true,
            isOper: true,
            serverSid: '001',
            displayHost: 'host.test',
            modes: '+ioH',
        ));

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())
            ->method('setUserMode')
            ->with('SID', 'UID123', '+qW');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'q', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder, $userLookup);

        self::assertTrue($applier->applyModesForNick('TestNick', $role));
    }

    #[Test]
    public function applyModesForNickReturnsFalseWhenUserNotInNetwork(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($this->createStub(ProtocolModuleInterface::class));

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder, $userLookup);

        self::assertFalse($applier->applyModesForNick('TestNick', $role));
    }

    #[Test]
    public function applyModesForNickIsCaseInsensitive(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            uid: 'UID123',
            nick: 'TestNick',
            ident: 'test',
            hostname: 'host.test',
            cloakedHost: 'hidden.host',
            ipBase64: 'AAAA',
            isIdentified: true,
            isOper: false,
            serverSid: '001',
            displayHost: 'host.test',
            modes: '+i',
        ));

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

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder, $userLookup);

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
    public function removeModesForNickReturnsTrueWhenUserDoesNotHaveModes(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            uid: 'UID123',
            nick: 'TestNick',
            ident: 'test',
            hostname: 'host.test',
            cloakedHost: 'hidden.host',
            ipBase64: 'AAAA',
            isIdentified: true,
            isOper: false,
            serverSid: '001',
            displayHost: 'host.test',
            modes: '+i',
        ));

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($this->createStub(ProtocolModuleInterface::class));

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder, $userLookup);

        self::assertTrue($applier->removeModesForNick('TestNick', $role));
    }

    #[Test]
    public function removeModesForNickRemovesOnlyModesUserHas(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            uid: 'UID123',
            nick: 'TestNick',
            ident: 'test',
            hostname: 'host.test',
            cloakedHost: 'hidden.host',
            ipBase64: 'AAAA',
            isIdentified: true,
            isOper: true,
            serverSid: '001',
            displayHost: 'host.test',
            modes: '+ioHq',
        ));

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())
            ->method('setUserMode')
            ->with('SID', 'UID123', '-Hq');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'q', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder, $userLookup);

        self::assertTrue($applier->removeModesForNick('TestNick', $role));
    }

    #[Test]
    public function removeModesForNickReturnsFalseWhenUserNotInNetwork(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($this->createStub(ProtocolModuleInterface::class));

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder, $userLookup);

        self::assertFalse($applier->removeModesForNick('TestNick', $role));
    }

    #[Test]
    public function updateModesForRoleDoesNothingWhenNoChanges(): void
    {
        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $applier = new IrcopModeApplier(
            new IdentifiedSessionRegistry(),
            $this->createStub(ActiveConnectionHolderInterface::class),
            $ircopRepository,
            $nickRepository,
            $userLookup,
            new NullLogger(),
        );

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'W']);

        $applier->updateModesForRole(1, ['H', 'W'], ['H', 'W']);

        self::assertTrue(true);
    }

    #[Test]
    public function updateModesForRoleSkipsUsersNotIdentified(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->setUserModes(['H', 'q']);
        $roleId = 1;

        $ircop = OperIrcop::create(42, $role, null, null);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByRoleId')->willReturn([$ircop]);

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findById')->willReturn(null);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $applier = new IrcopModeApplier(
            $identifiedRegistry,
            $this->createStub(ActiveConnectionHolderInterface::class),
            $ircopRepository,
            $nickRepository,
            $userLookup,
            new NullLogger(),
        );

        $applier->updateModesForRole($roleId, [], ['H', 'q']);

        self::assertTrue(true);
    }

    #[Test]
    public function updateModesForRoleAppliesAndRemovesDiffs(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            uid: 'UID123',
            nick: 'TestNick',
            ident: 'test',
            hostname: 'host.test',
            cloakedHost: 'hidden.host',
            ipBase64: 'AAAA',
            isIdentified: true,
            isOper: true,
            serverSid: '001',
            displayHost: 'host.test',
            modes: '+iH',
        ));

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
            $userLookup,
            new NullLogger(),
        );

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

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findById')->willReturn(null);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $applier = new IrcopModeApplier(
            $identifiedRegistry,
            $connectionHolder,
            $ircopRepository,
            $nickRepository,
            $userLookup,
            new NullLogger(),
        );

        $applier->updateModesForRole($roleId, [], ['q']);

        self::assertTrue(true);
    }

    #[Test]
    public function updateModesForRoleSkipsIrcopWithUserIdentifiedButNotInRegistry(): void
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

        $nick = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findById')->willReturn($nick);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);

        $applier = new IrcopModeApplier(
            $identifiedRegistry,
            $connectionHolder,
            $ircopRepository,
            $nickRepository,
            $userLookup,
            new NullLogger(),
        );

        $applier->updateModesForRole($roleId, [], ['q']);

        self::assertTrue(true);
    }

    #[Test]
    public function updateModesForRoleOnlyChangesModesThatDifferFromCurrent(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            uid: 'UID123',
            nick: 'TestNick',
            ident: 'test',
            hostname: 'host.test',
            cloakedHost: 'hidden.host',
            ipBase64: 'AAAA',
            isIdentified: true,
            isOper: true,
            serverSid: '001',
            displayHost: 'host.test',
            modes: '+ioHsW',
        ));

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::once())
            ->method('setUserMode')
            ->with('SID', 'UID123', '+q');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
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
            $userLookup,
            new NullLogger(),
        );

        $applier->updateModesForRole($roleId, ['H', 'W'], ['H', 'q', 'W']);
    }

    #[Test]
    public function updateModesForRoleSkipsUserNotInNetwork(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(null);

        $serviceActions = $this->createMock(ProtocolServiceActionsInterface::class);
        $serviceActions->expects(self::never())->method('setUserMode');

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
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
            $userLookup,
            new NullLogger(),
        );

        $applier->updateModesForRole($roleId, ['H'], ['q']);
    }
}
