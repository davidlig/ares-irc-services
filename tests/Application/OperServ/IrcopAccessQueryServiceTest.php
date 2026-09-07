<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ;

use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\IrcopAccessQueryService;
use App\Application\OperServ\RootUserRegistry;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcopAccessQueryService::class)]
final class IrcopAccessQueryServiceTest extends TestCase
{
    #[Test]
    public function exposesOnlySemanticIrcopAccessChecks(): void
    {
        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(7);
        $ircop = OperIrcop::create(42, $role, null, null);

        $ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $ircopRepository->method('findByNickId')->willReturnMap([[42, $ircop]]);
        $roleRepository = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepository->method('hasPermission')->willReturnCallback(
            static fn (int $roleId, string $permission): bool => 7 === $roleId && 'nickserv.saset' === $permission,
        );

        $query = new IrcopAccessQueryService(new IrcopAccessHelper(
            new RootUserRegistry('RootAdmin'),
            $ircopRepository,
            $roleRepository,
        ));

        self::assertTrue($query->isRoot('rootadmin'));
        self::assertTrue($query->isIrcop(42, 'OperUser'));
        self::assertTrue($query->hasPermission(42, 'OperUser', 'nickserv.saset'));
        self::assertTrue($query->hasAnyPermission(42, 'OperUser', ['nickserv.missing', 'nickserv.saset']));
    }
}
