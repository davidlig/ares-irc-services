<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application;

use App\OperServ\Application\ProtectedNickQueryService;
use App\OperServ\Application\RootUserRegistry;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperRole;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtectedNickQueryService::class)]
final class ProtectedNickQueryServiceTest extends TestCase
{
    #[Test]
    public function queriesRootAndIrcopProtection(): void
    {
        $role = OperRole::create('Admin', 'Description');
        $role->changeForcedVhostPattern('staff.example.com');
        $ircop = OperIrcop::create(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), 42, $role);
        $repository = $this->createStub(OperIrcopRepositoryInterface::class);
        $repository->method('findByNickId')->willReturnMap([[42, $ircop], [99, null]]);
        $query = new ProtectedNickQueryService(new RootUserRegistry('RootAdmin'), $repository);

        self::assertTrue($query->isRootNickname('rootadmin'));
        self::assertFalse($query->isRootNickname('Other'));
        self::assertTrue($query->isIrcopNickId(42));
        self::assertFalse($query->isIrcopNickId(99));
        self::assertTrue($query->hasForcedVhost(42));
        self::assertFalse($query->hasForcedVhost(99));
        self::assertSame('Alice.staff.example.com', $query->resolveForcedVhost(42, 'Alice'));
        self::assertNull($query->resolveForcedVhost(99, 'Alice'));
    }
}
