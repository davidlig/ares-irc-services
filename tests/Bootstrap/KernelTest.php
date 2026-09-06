<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\Bootstrap\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class KernelTest extends TestCase
{
    public function testKernelInitialization(): void
    {
        $kernel = new Kernel('test', true);

        self::assertSame('test', $kernel->getEnvironment());
        self::assertTrue($kernel->isDebug());
    }
}
