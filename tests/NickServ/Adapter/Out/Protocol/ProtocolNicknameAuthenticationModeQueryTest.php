<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Protocol;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\NativeNicknameAuthenticationInterface;
use App\Irc\Application\Port\In\ProtocolModuleInterface;
use App\NickServ\Adapter\Out\Protocol\ProtocolNicknameAuthenticationModeQuery;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolNicknameAuthenticationModeQuery::class)]
final class ProtocolNicknameAuthenticationModeQueryTest extends TestCase
{
    #[Test]
    public function mapsTheNativeAuthenticationCapabilityToTheNativeMode(): void
    {
        $holder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($this->createStub(NativeAuthenticationProtocol::class));
        $query = new ProtocolNicknameAuthenticationModeQuery($holder);

        self::assertSame(NicknameAuthenticationMode::NativeNick, $query->current());
    }

    #[Test]
    public function defaultsToTheServiceCommandMode(): void
    {
        $holder = $this->createStub(ActiveProtocolModuleHolderInterface::class);
        $holder->method('getProtocolModule')->willReturn($this->createStub(ProtocolModuleInterface::class));
        $query = new ProtocolNicknameAuthenticationModeQuery($holder);

        self::assertSame(NicknameAuthenticationMode::ServiceCommand, $query->current());
    }

    #[Test]
    public function defaultsToTheServiceCommandModeBeforeAProtocolIsActive(): void
    {
        $query = new ProtocolNicknameAuthenticationModeQuery(
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
        );

        self::assertSame(NicknameAuthenticationMode::ServiceCommand, $query->current());
    }
}

interface NativeAuthenticationProtocol extends ProtocolModuleInterface, NativeNicknameAuthenticationInterface {}
