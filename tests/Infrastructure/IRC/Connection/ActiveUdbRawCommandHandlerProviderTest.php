<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Connection;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\ProtocolModuleInterface;
use App\Application\Port\UdbRawCommandHandlerInterface;
use App\Infrastructure\IRC\Connection\ActiveUdbRawCommandHandlerProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ActiveUdbRawCommandHandlerProvider::class)]
final class ActiveUdbRawCommandHandlerProviderTest extends TestCase
{
    #[Test]
    public function returnsActiveModuleWhenItProvidesTheCapability(): void
    {
        $module = $this->createStubForIntersectionOfInterfaces([
            ProtocolModuleInterface::class,
            UdbRawCommandHandlerInterface::class,
        ]);
        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($module);

        self::assertSame($module, new ActiveUdbRawCommandHandlerProvider($holder)->getActiveHandler());
    }

    #[Test]
    public function returnsNullWhenActiveModuleDoesNotProvideTheCapability(): void
    {
        $holder = $this->createStub(ActiveConnectionHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($this->createStub(ProtocolModuleInterface::class));

        self::assertNull(new ActiveUdbRawCommandHandlerProvider($holder)->getActiveHandler());
    }
}
