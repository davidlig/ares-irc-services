<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol;

use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\IRC\Protocol\ProtocolHandlerRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolHandlerRegistry::class)]
final class ProtocolHandlerRegistryTest extends TestCase
{
    #[Test]
    public function supportsReturnsFalseWhenNoHandlerRegistered(): void
    {
        $registry = new ProtocolHandlerRegistry([]);
        self::assertFalse($registry->supports('unreal'));
    }

    #[Test]
    public function registerAndGetReturnsHandler(): void
    {
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('getProtocolName')->willReturn('unreal');
        $registry = new ProtocolHandlerRegistry([]);
        $registry->register($handler);
        self::assertTrue($registry->supports('unreal'));
        self::assertSame($handler, $registry->get('unreal'));
    }

    #[Test]
    public function getRegisteredProtocolsReturnsRegisteredNames(): void
    {
        $h1 = $this->createStub(ProtocolHandlerInterface::class);
        $h1->method('getProtocolName')->willReturn('unreal');
        $h2 = $this->createStub(ProtocolHandlerInterface::class);
        $h2->method('getProtocolName')->willReturn('inspircd');
        $registry = new ProtocolHandlerRegistry([$h1, $h2]);
        self::assertSame(['unreal', 'inspircd'], $registry->getRegisteredProtocols());
    }

    #[Test]
    public function getThrowsWhenProtocolNotSupported(): void
    {
        $registry = new ProtocolHandlerRegistry([]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No protocol handler registered for "unknown"');
        $registry->get('unknown');
    }

    #[Test]
    public function constructorAcceptsIterableAndRegistersHandlers(): void
    {
        $handler = $this->createStub(ProtocolHandlerInterface::class);
        $handler->method('getProtocolName')->willReturn('unreal');
        $registry = new ProtocolHandlerRegistry([$handler]);
        self::assertTrue($registry->supports('unreal'));
        self::assertSame($handler, $registry->get('unreal'));
    }
}
