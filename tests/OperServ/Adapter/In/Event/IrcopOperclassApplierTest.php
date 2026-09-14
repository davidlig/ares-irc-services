<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\OperServ\Adapter\In\Event\IrcopOperclassApplier;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(IrcopOperclassApplier::class)]
final class IrcopOperclassApplierTest extends TestCase
{
    private function createModule(ProtocolServiceActionsInterface $actions, ?string $serverSid = '001'): ActiveProtocolModuleHolderInterface
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($actions);

        $holder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($module);
        $holder->method('getServerSid')->willReturn($serverSid);

        return $holder;
    }

    private function createNonOperclassModule(string $serverSid = '001'): ActiveProtocolModuleHolderInterface
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($this->createStub(ProtocolServiceActionsInterface::class));

        $holder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($module);
        $holder->method('getServerSid')->willReturn($serverSid);

        return $holder;
    }

    private function createApplier(
        ?IdentifiedSessionRegistry $registry = null,
        ?ActiveProtocolModuleHolderInterface $holder = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
        ?NickProjectionQuery $nickRepo = null,
    ): IrcopOperclassApplier {
        return new IrcopOperclassApplier(
            $registry ?? new IdentifiedSessionRegistry(),
            $holder ?? $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
            $nickRepo ?? $this->createStub(NickProjectionQuery::class),
        );
    }

    #[Test]
    public function applyForNickReturnsFalseWhenTheRoleHasNoOperclass(): void
    {
        self::assertFalse($this->createApplier()->applyForNick('TestNick', OperRole::create('ADMIN')));
    }

    #[Test]
    public function applyForNickReturnsFalseWhenNotIdentified(): void
    {
        $role = OperRole::create('ADMIN');
        $role->changeOperclass('services:admin');

        self::assertFalse($this->createApplier()->applyForNick('TestNick', $role));
    }

    #[Test]
    public function applyForNickReturnsFalseWhenTheProtocolDoesNotSupportIt(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');
        $holder = $this->createNonOperclassModule();

        $role = OperRole::create('ADMIN');
        $role->changeOperclass('services:admin');

        self::assertFalse($this->createApplier($identifiedRegistry, $holder)->applyForNick('TestNick', $role));
    }

    #[Test]
    public function applyForNickSendsTheOperclass(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');
        $actions = new RecordingOperclassActions();
        $role = OperRole::create('ADMIN');
        $role->changeOperclass('services:admin');

        self::assertTrue($this->createApplier($identifiedRegistry, $this->createModule($actions))->applyForNick('TestNick', $role));
        self::assertSame([['001', 'UID123', 'TestNick', 'services:admin']], $actions->operclassCalls);
    }

    #[Test]
    public function applyAndRemoveReturnFalseWhenServerSidIsMissing(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');
        $actions = new RecordingOperclassActions();
        $holder = $this->createModule($actions, null);
        $role = OperRole::create('ADMIN');
        $role->changeOperclass('services:admin');
        $applier = $this->createApplier($identifiedRegistry, $holder);

        self::assertFalse($applier->applyForNick('TestNick', $role));
        self::assertFalse($applier->removeForNick('TestNick'));
        self::assertSame([], $actions->operclassCalls);
    }

    #[Test]
    public function removeForNickReturnsFalseWhenNotIdentified(): void
    {
        self::assertFalse($this->createApplier()->removeForNick('TestNick'));
    }

    #[Test]
    public function removeForNickSendsANullOperclass(): void
    {
        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');
        $actions = new RecordingOperclassActions();

        self::assertTrue($this->createApplier($identifiedRegistry, $this->createModule($actions))->removeForNick('TestNick'));
        self::assertSame([['001', 'UID123', 'TestNick', null]], $actions->operclassCalls);
    }

    #[Test]
    public function updateForRoleSkipsUnknownNicksAndAppliesTheRest(): void
    {
        $role = OperRole::create('ADMIN');
        $role->changeOperclass('services:admin');
        new ReflectionClass($role)->getProperty('id')->setValue($role, 5);

        $nick = new NickProjection(7, 'TestNick', 'hash', null, false, null);

        $missingNickIrcop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 99, $role);
        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 7, $role);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByRoleId')->willReturn([$missingNickIrcop, $ircop]);
        $nickRepository = $this->createStub(NickProjectionQuery::class);
        $nickRepository->method('findById')->willReturnCallback(static fn (int $id): ?NickProjection => 99 === $id ? null : $nick);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');
        $actions = new RecordingOperclassActions();

        $applier = $this->createApplier($identifiedRegistry, $this->createModule($actions), $ircopRepository, $nickRepository);

        $applier->updateForRole(5, 'services:admin');

        self::assertSame([['001', 'UID123', 'TestNick', 'services:admin']], $actions->operclassCalls);
    }

    #[Test]
    public function updateForRoleRemovesTheOperclassWhenCleared(): void
    {
        $role = OperRole::create('ADMIN');
        new ReflectionClass($role)->getProperty('id')->setValue($role, 5);

        $nick = new NickProjection(7, 'TestNick', 'hash', null, false, null);

        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 7, $role);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByRoleId')->willReturn([$ircop]);
        $nickRepository = $this->createStub(NickProjectionQuery::class);
        $nickRepository->method('findById')->willReturn($nick);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');
        $actions = new RecordingOperclassActions();

        $applier = $this->createApplier($identifiedRegistry, $this->createModule($actions), $ircopRepository, $nickRepository);

        $applier->updateForRole(5, null);

        self::assertSame([['001', 'UID123', 'TestNick', null]], $actions->operclassCalls);
    }
}
