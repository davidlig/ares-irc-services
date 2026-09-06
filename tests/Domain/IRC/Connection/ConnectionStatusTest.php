<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Connection;

use App\Irc\Adapter\Out\Connection\ConnectionStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConnectionStatus::class)]
final class ConnectionStatusTest extends TestCase
{
    #[Test]
    public function allCasesExist(): void
    {
        $caseNames = array_map(
            static fn (ConnectionStatus $status): string => $status->name,
            ConnectionStatus::cases(),
        );

        $uniqueCaseNames = array_values(array_unique($caseNames));

        self::assertCount(6, $uniqueCaseNames);
        self::assertContains('Disconnected', $uniqueCaseNames);
        self::assertContains('Connecting', $uniqueCaseNames);
        self::assertContains('Connected', $uniqueCaseNames);
        self::assertContains('Authenticating', $uniqueCaseNames);
        self::assertContains('Authenticated', $uniqueCaseNames);
        self::assertContains('Error', $uniqueCaseNames);
    }
}
