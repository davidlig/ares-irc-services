<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\UserModeSupportInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use UnexpectedValueException;

use function is_string;

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

    private function createUserModeSupportStub(): UserModeSupportInterface
    {
        $support = $this->createStub(UserModeSupportInterface::class);
        $support->method('buildModeParams')->willReturnCallback(
            static function (string $sign, array $modes): array {
                $modeString = '';
                foreach ($modes as $mode) {
                    if (!is_string($mode)) {
                        throw new UnexpectedValueException('User modes must be strings.');
                    }

                    $modeString .= $mode;
                }

                return [$sign . $modeString, []];
            },
        );

        return $support;
    }

    private function createModuleWithUserModeSupport(ProtocolServiceActionsInterface $serviceActions): ProtocolModuleInterface
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);
        $module->method('getUserModeSupport')->willReturn($this->createUserModeSupportStub());

        return $module;
    }

    #[Test]
    public function applyAndRemoveReturnFalseWhenServerSidIsMissing(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            'UID123',
            'TestNick',
            'test',
            'host.test',
            'hidden.host',
            'AAAA',
            isIdentified: true,
            modes: '+i',
        ));
        $actions = $this->createMock(ProtocolServiceActionsInterface::class);
        $actions->expects(self::never())->method('setUserMode');
        $module = $this->createModuleWithUserModeSupport($actions);
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn(null);
        $applyRole = OperRole::create('ADMIN', 'Admin role');
        $applyRole->changeUserModes(['H']);
        $removeRole = OperRole::create('ADMIN', 'Admin role');
        $removeRole->changeUserModes(['i']);
        $applier = $this->createApplier($identifiedRegistry, $connectionHolder, $userLookup);

        self::assertFalse($applier->applyModesForNick('TestNick', $applyRole));
        self::assertFalse($applier->removeModesForNick('TestNick', $removeRole));
    }

    #[Test]
    public function emptyCurrentModesAreHandledWhenRemovingModes(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            'UID123',
            'TestNick',
            'test',
            'host.test',
            'hidden.host',
            'AAAA',
            isIdentified: true,
            modes: '',
        ));
        $module = $this->createModuleWithUserModeSupport($this->createStub(ProtocolServiceActionsInterface::class));
        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $role = OperRole::create('ADMIN', 'Admin role');
        $role->changeUserModes(['H']);

        self::assertTrue($this->createApplier($identifiedRegistry, $connectionHolder, $userLookup)->removeModesForNick('TestNick', $role));
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
        $role->changeUserModes(['H', 'W']);
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

        $packedIp = inet_pton('10.0.0.1');
        if (false === $packedIp) {
            self::fail('Expected a packed IPv4 address.');
        }

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByUid')->willReturn(new SenderView(
            uid: 'UID123',
            nick: 'TestNick',
            ident: 'test',
            hostname: 'host.test',
            cloakedHost: 'hidden.host',
            ipBase64: base64_encode($packedIp),
            isIdentified: true,
        ));

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->changeUserModes(['H', 'W']);

        $applier = $this->createApplier($identifiedRegistry, $connectionHolder, $userLookup);

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
        $role->changeUserModes(['H', 'W']);

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
            ->with('SID', 'UID123', '+qW', []);

        $module = $this->createModuleWithUserModeSupport($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->changeUserModes(['H', 'q', 'W']);

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
        $role->changeUserModes(['H', 'W']);

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
            ->with('SID', 'UID123', '+HW', []);

        $module = $this->createModuleWithUserModeSupport($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->changeUserModes(['H', 'W']);

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
        $role->changeUserModes(['H', 'W']);
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
        $role->changeUserModes(['H', 'W']);

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
        $role->changeUserModes(['H', 'W']);

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
            ->with('SID', 'UID123', '-Hq', []);

        $module = $this->createModuleWithUserModeSupport($serviceActions);

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->changeUserModes(['H', 'q', 'W']);

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
        $role->changeUserModes(['H', 'W']);

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
        $role->changeUserModes(['H', 'W']);

        $applier->updateModesForRole(1, ['H', 'W'], ['H', 'W']);

        self::assertSame(['H', 'W'], $role->getUserModes());
    }

    #[Test]
    public function updateModesForRoleSkipsUsersNotIdentified(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->changeUserModes(['H', 'q']);
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

        self::assertSame(['H', 'q'], $role->getUserModes());
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
            ->willReturnCallback(static function (string $sid, string $uid, string $modes, array $params = []): void {
                self::assertSame('SID', $sid);
                self::assertSame('UID123', $uid);
                self::assertTrue('-H' === $modes || '+q' === $modes);
            });

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);
        $module->method('getUserModeSupport')->willReturn($this->createUserModeSupportStub());

        $connectionHolder = $this->createStub(ActiveConnectionHolderInterface::class);
        $connectionHolder->method('getProtocolModule')->willReturn($module);
        $connectionHolder->method('getServerSid')->willReturn('SID');

        $role = OperRole::create('ADMIN', 'Admin role');
        $role->changeUserModes(['q']);
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
        $role->changeUserModes(['q']);
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

        self::assertSame(['q'], $role->getUserModes());
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
        $role->changeUserModes(['q']);
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

        self::assertSame(['q'], $role->getUserModes());
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
            ->with('SID', 'UID123', '+q', []);

        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($serviceActions);
        $module->method('getUserModeSupport')->willReturn($this->createUserModeSupportStub());

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
        $module->method('getUserModeSupport')->willReturn($this->createUserModeSupportStub());

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
