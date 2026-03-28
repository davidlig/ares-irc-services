<?php

declare(strict_types=1);

namespace App\Tests\Application\OperServ\Security;

use App\Application\OperServ\Security\OperServPermission;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

use function sprintf;

#[CoversClass(OperServPermission::class)]
final class OperServPermissionTest extends TestCase
{
    #[Test]
    public function killConstantHasExpectedValue(): void
    {
        self::assertSame('operserv.kill', OperServPermission::KILL);
    }

    #[Test]
    public function allConstantsAreStrings(): void
    {
        $reflection = new ReflectionClass(OperServPermission::class);
        $constants = $reflection->getReflectionConstants();

        foreach ($constants as $constant) {
            if ($constant->isPublic()) {
                self::assertIsString($constant->getValue(), sprintf('Constant %s should be a string', $constant->getName()));
            }
        }
    }

    #[Test]
    public function constructorIsPrivate(): void
    {
        $reflection = new ReflectionClass(OperServPermission::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($constructor->isPrivate(), 'Constructor should be private');
    }

    #[Test]
    public function cannotBeInstantiated(): void
    {
        $reflection = new ReflectionClass(OperServPermission::class);
        $constructor = $reflection->getConstructor();

        self::assertTrue($constructor->isPrivate(), 'Class should not be instantiable');

        $constructor->setAccessible(true);
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($instance);

        self::assertInstanceOf(OperServPermission::class, $instance);
    }
}
