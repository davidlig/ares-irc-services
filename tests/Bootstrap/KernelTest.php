<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\Kernel;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class KernelTest extends TestCase
{
    #[Test]
    public function kernelInitialization(): void
    {
        $kernel = new Kernel('test', true);

        self::assertSame('test', $kernel->getEnvironment());
        self::assertTrue($kernel->isDebug());
    }
}
