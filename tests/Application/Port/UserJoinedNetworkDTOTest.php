<?php

declare(strict_types=1);

namespace App\Tests\Application\Port;

use App\Application\Port\UserJoinedNetworkDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserJoinedNetworkDTO::class)]
final class UserJoinedNetworkDTOTest extends TestCase
{
    #[Test]
    public function storesRequiredAndDefaultValues(): void
    {
        $dto = new UserJoinedNetworkDTO('UID1', 'User', 'ident', 'host', 'cloak', 'ip', 'display');

        self::assertSame('UID1', $dto->uid);
        self::assertSame('User', $dto->nick);
        self::assertSame('ident', $dto->ident);
        self::assertSame('host', $dto->hostname);
        self::assertSame('cloak', $dto->cloakedHost);
        self::assertSame('ip', $dto->ipBase64);
        self::assertSame('display', $dto->displayHost);
        self::assertFalse($dto->isIdentified);
        self::assertFalse($dto->isOper);
        self::assertSame('', $dto->serverSid);
    }

    #[Test]
    public function storesOptionalFlagsAndServer(): void
    {
        $dto = new UserJoinedNetworkDTO('UID1', 'User', 'ident', 'host', 'cloak', 'ip', 'display', true, true, 'SID1');

        self::assertTrue($dto->isIdentified);
        self::assertTrue($dto->isOper);
        self::assertSame('SID1', $dto->serverSid);
    }
}
