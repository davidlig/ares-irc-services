<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\OperServ\ProtectedNickQueryService;
use App\Application\OperServ\RootUserRegistry;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
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
        $ircop = OperIrcop::create(42, $role);
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
