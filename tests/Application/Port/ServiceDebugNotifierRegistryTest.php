<?php

declare(strict_types=1);

namespace App\Tests\Application\Port;

use App\Application\Port\ServiceDebugNotifierInterface;
use App\Application\Port\ServiceDebugNotifierRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceDebugNotifierRegistry::class)]
final class ServiceDebugNotifierRegistryTest extends TestCase
{
    #[Test]
    public function getReturnsNotifierWhenExists(): void
    {
        $nickservNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $nickservNotifier->method('getServiceName')->willReturn('nickserv');

        $operservNotifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $operservNotifier->method('getServiceName')->willReturn('operserv');

        $registry = new ServiceDebugNotifierRegistry([$nickservNotifier, $operservNotifier]);

        self::assertSame($nickservNotifier, $registry->get('nickserv'));
        self::assertSame($operservNotifier, $registry->get('operserv'));
    }

    #[Test]
    public function getReturnsNullWhenNotFound(): void
    {
        $notifier = $this->createStub(ServiceDebugNotifierInterface::class);
        $notifier->method('getServiceName')->willReturn('nickserv');

        $registry = new ServiceDebugNotifierRegistry([$notifier]);

        self::assertNull($registry->get('chanserv'));
    }

    #[Test]
    public function emptyRegistryReturnsNull(): void
    {
        $registry = new ServiceDebugNotifierRegistry([]);

        self::assertNull($registry->get('nickserv'));
    }
}
