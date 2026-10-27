<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbChecksum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_unique;
use function hash;
use function str_repeat;
use function strlen;
use function strtoupper;

#[CoversClass(UdbChecksum::class)]
final class UdbChecksumTest extends TestCase
{
    #[Test]
    public function parseAcceptsOnlyCanonicalLowercaseSha256(): void
    {
        $digest = str_repeat('a', 64);
        self::assertSame($digest, UdbChecksum::parse($digest));
        self::assertNull(UdbChecksum::parse(strtoupper($digest)));
        self::assertNull(UdbChecksum::parse(str_repeat('a', 63)));
        self::assertNull(UdbChecksum::parse(str_repeat('g', 64)));
        self::assertNull(UdbChecksum::parse(''));
    }

    #[Test]
    public function nativeEmptyDigestIsBlockSpecificAndStable(): void
    {
        $digests = [];
        foreach (UdbBlock::all() as $block) {
            $digest = UdbChecksum::empty($block);
            self::assertSame(64, strlen($digest));
            self::assertSame($digest, UdbChecksum::fromRecords($block, []));
            self::assertSame($digest, UdbChecksum::empty($block));
            $digests[] = $digest;
        }

        self::assertCount(6, array_unique($digests));
        self::assertNotSame(hash('sha256', ''), $digests[0]);
    }
}
