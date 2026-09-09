<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol;

use App\Irc\Adapter\Protocol\RawCommandInterception;
use App\Irc\Adapter\Protocol\RawCommandInterceptionFailure;
use App\Irc\Adapter\Protocol\RawCommandInterceptionOutcome;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RawCommandInterception::class)]
#[CoversClass(RawCommandInterceptionFailure::class)]
#[CoversClass(RawCommandInterceptionOutcome::class)]
final class RawCommandInterceptionTest extends TestCase
{
    #[Test]
    public function representsAnUnhandledCommand(): void
    {
        $result = RawCommandInterception::notHandled();

        self::assertSame(RawCommandInterceptionOutcome::NotHandled, $result->outcome);
        self::assertNull($result->operation);
        self::assertNull($result->failure);
        self::assertNull($result->resourceType);
        self::assertNull($result->resourceIdentifier);
    }

    #[Test]
    public function representsAProtocolOwnedExecution(): void
    {
        $result = RawCommandInterception::executed('PROTOCOL ACTION');

        self::assertSame(RawCommandInterceptionOutcome::Executed, $result->outcome);
        self::assertSame('PROTOCOL ACTION', $result->operation);
        self::assertNull($result->failure);
    }

    #[Test]
    public function representsAProtocolOwnedRejection(): void
    {
        $result = RawCommandInterception::rejected(
            RawCommandInterceptionFailure::ValueInvalid,
            'record',
            'identifier',
        );

        self::assertSame(RawCommandInterceptionOutcome::Rejected, $result->outcome);
        self::assertSame(RawCommandInterceptionFailure::ValueInvalid, $result->failure);
        self::assertSame('record', $result->resourceType);
        self::assertSame('identifier', $result->resourceIdentifier);
    }
}
