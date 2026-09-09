<?php

declare(strict_types=1);

namespace App\Tests\Irc\Application\Port\In;

use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\Irc\Application\Port\In\ServiceDebugNotifierRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceDebugNotifierRegistry::class)]
final class ServiceDebugNotifierRegistryTest extends TestCase
{
    #[Test]
    public function indexesNotifiersByServiceNameAndReturnsNullForUnknownService(): void
    {
        $nickServ = $this->createStub(ServiceDebugNotifierInterface::class);
        $nickServ->method('getServiceName')->willReturn('NickServ');
        $operServ = $this->createStub(ServiceDebugNotifierInterface::class);
        $operServ->method('getServiceName')->willReturn('OperServ');

        $registry = new ServiceDebugNotifierRegistry([$nickServ, $operServ]);

        self::assertSame($nickServ, $registry->get('NickServ'));
        self::assertSame($operServ, $registry->get('OperServ'));
        self::assertNull($registry->get('MemoServ'));
    }
}
