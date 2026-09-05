<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\IrcopOperclassApplier;
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
use ReflectionClass;

#[CoversClass(IrcopOperclassApplier::class)]
final class IrcopOperclassApplierTest extends TestCase
{
    private function createModule(ProtocolServiceActionsInterface $actions, string $serverSid = '001'): ActiveConnectionHolderInterface
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($actions);

        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($module);
        $holder->method('getServerSid')->willReturn($serverSid);

        return $holder;
    }

    private function createNonOperclassModule(string $serverSid = '001'): ActiveConnectionHolderInterface
    {
        $module = $this->createStub(ProtocolModuleInterface::class);
        $module->method('getServiceActions')->willReturn($this->createStub(ProtocolServiceActionsInterface::class));

        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($module);
        $holder->method('getServerSid')->willReturn($serverSid);

        return $holder;
    }

    private function createApplier(
        ?IdentifiedSessionRegistry $registry = null,
        ?ActiveConnectionHolderInterface $holder = null,
        ?OperIrcopRepositoryInterface $ircopRepo = null,
        ?RegisteredNickRepositoryInterface $nickRepo = null,
    ): IrcopOperclassApplier {
        return new IrcopOperclassApplier(
            $registry ?? new IdentifiedSessionRegistry(),
            $holder ?? $this->createStub(ActiveConnectionHolderInterface::class),
            $ircopRepo ?? $this->createStub(OperIrcopRepositoryInterface::class),
            $nickRepo ?? $this->createStub(RegisteredNickRepositoryInterface::class),
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

        $nick = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $missingNickIrcop = $this->createStub(OperIrcop::class);
        $missingNickIrcop->method('getNickId')->willReturn(99);
        $missingNickIrcop->method('getRole')->willReturn($role);

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getNickId')->willReturn(7);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByRoleId')->willReturn([$missingNickIrcop, $ircop]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findById')->willReturnCallback(static fn (int $id): ?RegisteredNick => 99 === $id ? null : $nick);

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

        $nick = RegisteredNick::createPending('TestNick', 'hash', 'test@example.com', 'en', new DateTimeImmutable('+1 hour'));
        $nick->activate();

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getNickId')->willReturn(7);
        $ircop->method('getRole')->willReturn($role);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByRoleId')->willReturn([$ircop]);
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepository->method('findById')->willReturn($nick);

        $identifiedRegistry = new IdentifiedSessionRegistry();
        $identifiedRegistry->register('UID123', 'TestNick');
        $actions = new RecordingOperclassActions();

        $applier = $this->createApplier($identifiedRegistry, $this->createModule($actions), $ircopRepository, $nickRepository);

        $applier->updateForRole(5, null);

        self::assertSame([['001', 'UID123', 'TestNick', null]], $actions->operclassCalls);
    }
}
