<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbStreamOrigin;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
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
        $mutation = new UdbMutation('N', 'davidlig::vhost', UdbValue::string('users.example.net'));

        self::assertSame('N', $mutation->block);
        self::assertSame('davidlig::vhost', $mutation->encodedPath);
        self::assertTrue(UdbValue::string('users.example.net') == $mutation->value);
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
        $mutation = new UdbMutation('N', 'davidlig::vhost', UdbValue::string('users.example.net'));
        $sequence = UdbUnsignedDecimal::fromInt(42);
        $origin = new UdbStreamOrigin('002', '0123456789abcdef');

        $sequenced = $mutation->assigned($sequence, $origin);

        self::assertNotSame($mutation, $sequenced);
        self::assertNull($mutation->sequence);
        self::assertSame($sequence, $sequenced->sequence);
        self::assertSame($origin, $sequenced->origin);
        self::assertSame($mutation->block, $sequenced->block);
        self::assertSame($mutation->encodedPath, $sequenced->encodedPath);
        self::assertSame($mutation->value, $sequenced->value);
    }
}
