<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Application\Security;

use App\CRegServ\Application\Security\CRegServPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(CRegServPermission::class)]
final class CRegServPermissionTest extends TestCase
{
    #[Test]
    public function allIrcopReturnsEveryPermissionWithItsCommandSetValue(): void
    {
        self::assertSame([
            'cregserv.aprobar',
            'cregserv.denegar',
            'cregserv.pendientes',
            'cregserv.denegados',
            'cregserv.drop',
            'cregserv.saregister',
        ], CRegServPermission::allIrcop());
    }

    #[Test]
    public function constructorCanBeInvokedViaReflectionForCoverage(): void
    {
        $reflection = new ReflectionClass(CRegServPermission::class);
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        $constructor->invoke($reflection->newInstanceWithoutConstructor());
        $this->addToAssertionCount(1);
    }
}
