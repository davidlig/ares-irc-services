<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\ActiveUdbRawCommandHandlerProvider;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;
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
