<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbMutation;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbMutation::class)]
final class UdbMutationTest extends TestCase
{
    #[Test]
    public function itCarriesAnEncodedInsertion(): void
    {
        $mutation = new UdbMutation('N', 'davidlig::vhost', 'users.example.net');

        self::assertSame('N', $mutation->block);
        self::assertSame('davidlig::vhost', $mutation->encodedPath);
        self::assertSame('users.example.net', $mutation->value);
    }

    #[Test]
    public function nullValueRepresentsDeletion(): void
    {
        $mutation = new UdbMutation('S', 'nickserv', null);

        self::assertSame('S', $mutation->block);
        self::assertSame('nickserv', $mutation->encodedPath);
        self::assertNull($mutation->value);
    }

    #[Test]
    public function itCreatesAnImmutableSequencedCopy(): void
    {
        $mutation = new UdbMutation('N', 'davidlig::vhost', 'users.example.net');
        $sequence = UdbUnsignedDecimal::fromInt(42);

        $sequenced = $mutation->sequenced($sequence);

        self::assertNotSame($mutation, $sequenced);
        self::assertNull($mutation->sequence);
        self::assertSame($sequence, $sequenced->sequence);
        self::assertSame($mutation->block, $sequenced->block);
        self::assertSame($mutation->encodedPath, $sequenced->encodedPath);
        self::assertSame($mutation->value, $sequenced->value);
    }
}
