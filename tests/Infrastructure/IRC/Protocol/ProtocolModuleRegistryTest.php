<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol;

use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\ProtocolServiceActionsInterface;
use App\Application\Port\ServiceIntroductionFormatterInterface;
use App\Application\Port\VhostCommandBuilderInterface;
use App\Domain\IRC\Protocol\ProtocolHandlerInterface;
use App\Infrastructure\IRC\Protocol\ProtocolModuleRegistry;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(ProtocolModuleRegistry::class)]
final class ProtocolModuleRegistryTest extends TestCase
{
    private function createModule(string $name): ProtocolModuleInterface
    {
        $m = $this->createMock(ProtocolModuleInterface::class);
        $m->method('getProtocolName')->willReturn($name);
        $m->method('getHandler')->willReturn($this->createStub(ProtocolHandlerInterface::class));
        $m->method('getServiceActions')->willReturn($this->createStub(ProtocolServiceActionsInterface::class));
        $m->method('getIntroductionFormatter')->willReturn($this->createStub(ServiceIntroductionFormatterInterface::class));
        $m->method('getVhostCommandBuilder')->willReturn($this->createStub(VhostCommandBuilderInterface::class));
        $m->method('getChannelModeSupport')->willReturn($this->createStub(ChannelModeSupportInterface::class));

        return $m;
    }

    #[Test]
    public function getReturnsModuleByProtocolName(): void
    {
        $unreal = $this->createModule('unreal');
        $registry = new ProtocolModuleRegistry([$unreal]);

        self::assertSame($unreal, $registry->get('unreal'));
    }

    #[Test]
    public function hasReturnsTrueForRegisteredProtocol(): void
    {
        $registry = new ProtocolModuleRegistry([$this->createModule('unreal')]);

        self::assertTrue($registry->has('unreal'));
        self::assertFalse($registry->has('other'));
    }

    #[Test]
    public function getRegisteredProtocolNamesReturnsAllNames(): void
    {
        $registry = new ProtocolModuleRegistry([
            $this->createModule('unreal'),
            $this->createModule('inspircd'),
        ]);

        $names = $registry->getRegisteredProtocolNames();
        self::assertCount(2, $names);
        self::assertContains('unreal', $names);
        self::assertContains('inspircd', $names);
    }

    #[Test]
    public function getThrowsForUnknownProtocol(): void
    {
        $registry = new ProtocolModuleRegistry([$this->createModule('unreal')]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No protocol module registered for "unknown"');

        $registry->get('unknown');
    }
}
