<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Protocol;

use App\Application\Port\UdbOfflineTakeoverInterface;
use App\Bootstrap\Protocol\ProtocolComposition;
use App\Domain\Udb\Repository\UdbAuthorityStateRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\UnrealUdbConnectionPreflight;
use App\Irc\Adapter\Protocol\NetworkStateAdapterInterface;
use App\Irc\Adapter\Runtime\ProtocolRuntimeModuleInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolComposition::class)]
final class ProtocolCompositionTest extends TestCase
{
    #[Test]
    public function returnsConfiguredModuleAndNetworkStateAdapter(): void
    {
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getProtocolName')->willReturn('unreal');
        $adapter = $this->createStub(NetworkStateAdapterInterface::class);
        $adapter->method('getSupportedProtocol')->willReturn('unreal');

        $composition = new ProtocolComposition('unreal', [$module], [$adapter], $this->createUdbPreflight());

        self::assertSame($module, $composition->protocolModule());
        self::assertSame($adapter, $composition->networkStateAdapter());
    }

    #[Test]
    public function regularProtocolPreflightIsImmediatelyReady(): void
    {
        $authority = $this->createMock(UdbAuthorityStateRepositoryInterface::class);
        $authority->expects(self::never())->method('isApproved');
        $composition = new ProtocolComposition('inspircd', [], [], $this->createUdbPreflight($authority));

        self::assertTrue($composition->prepare()->ready);
    }

    #[Test]
    public function unrealUdbPreflightIsDelegatedToItsIndependentAdapter(): void
    {
        $authority = $this->createMock(UdbAuthorityStateRepositoryInterface::class);
        $authority->expects(self::once())->method('isApproved')->willReturn(true);
        $composition = new ProtocolComposition('unrealudb', [], [], $this->createUdbPreflight($authority));

        self::assertTrue($composition->prepare()->ready);
    }

    #[Test]
    public function unknownModuleFailsWithAvailableNames(): void
    {
        $module = $this->createStub(ProtocolRuntimeModuleInterface::class);
        $module->method('getProtocolName')->willReturn('unreal');
        $composition = new ProtocolComposition('unknown', [$module], [], $this->createUdbPreflight());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No protocol module is registered for "unknown". Available: unreal.');

        $composition->protocolModule();
    }

    #[Test]
    public function unknownNetworkAdapterFailsWithAvailableNames(): void
    {
        $adapter = $this->createStub(NetworkStateAdapterInterface::class);
        $adapter->method('getSupportedProtocol')->willReturn('inspircd');
        $composition = new ProtocolComposition('unknown', [], [$adapter], $this->createUdbPreflight());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No network state adapter is registered for "unknown". Available: inspircd.');

        $composition->networkStateAdapter();
    }

    private function createUdbPreflight(?UdbAuthorityStateRepositoryInterface $authority = null): UnrealUdbConnectionPreflight
    {
        return new UnrealUdbConnectionPreflight(
            $authority ?? $this->createStub(UdbAuthorityStateRepositoryInterface::class),
            $this->createStub(UdbOfflineTakeoverInterface::class),
            '',
        );
    }
}
