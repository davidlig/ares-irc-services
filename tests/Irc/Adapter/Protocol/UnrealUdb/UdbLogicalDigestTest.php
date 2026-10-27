<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbValue;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbLogicalDigest;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_reverse;
use function file_get_contents;
use function json_decode;
use function str_pad;
use function str_repeat;

use const JSON_THROW_ON_ERROR;
use const STR_PAD_LEFT;

#[CoversClass(UdbLogicalDigest::class)]
final class UdbLogicalDigestTest extends TestCase
{
    #[Test]
    public function asciiCaseFoldingDoesNotCollapseDistinctFLineMasks(): void
    {
        $lower = [['F::MiXeD::reason', UdbValue::string('one')]];
        $sameIdentity = [['f::MiXeD::REASON', UdbValue::string('one')]];
        $differentMaskCase = [['F::mixed::reason', UdbValue::string('one')]];

        self::assertSame(UdbLogicalDigest::fromRecords(UdbBlock::Lines, $lower), UdbLogicalDigest::fromRecords(UdbBlock::Lines, $sameIdentity));
        self::assertNotSame(UdbLogicalDigest::fromRecords(UdbBlock::Lines, $lower), UdbLogicalDigest::fromRecords(UdbBlock::Lines, $differentMaskCase));
    }

    #[Test]
    public function typedKindAndBlockArePartOfTheDigest(): void
    {
        $string = [['nick::pass', UdbValue::string('*5')]];
        $number = [['nick::pass', UdbValue::number(UdbUnsignedDecimal::parse('5') ?? throw new LogicException('The test uint64 fixture must be valid.'))]];

        self::assertNotSame(UdbLogicalDigest::fromRecords(UdbBlock::Nicks, $string), UdbLogicalDigest::fromRecords(UdbBlock::Nicks, $number));
        self::assertNotSame(UdbLogicalDigest::fromRecords(UdbBlock::Nicks, $string), UdbLogicalDigest::fromRecords(UdbBlock::Channels, $string));
    }

    #[Test]
    public function recordInsertionOrderDoesNotChangePartitionRowOrdering(): void
    {
        $records = [['b::pass', UdbValue::string('second')], ['a::pass', UdbValue::string('first')]];

        self::assertSame(UdbLogicalDigest::fromRecords(UdbBlock::Nicks, $records), UdbLogicalDigest::fromRecords(UdbBlock::Nicks, array_reverse($records)));
    }

    #[Test]
    public function independentlyGeneratedEmptyAndRadixVectorsMatch(): void
    {
        $json = file_get_contents(__DIR__ . '/fixtures/native-udb4/logical-digest-vectors.json');
        self::assertIsString($json);
        $fixture = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fixture);
        self::assertIsArray($fixture['empty_six']);
        foreach (UdbBlock::all() as $block) {
            self::assertSame($fixture['empty_six'][$block->letter()], UdbLogicalDigest::fromRecords($block, []));
        }

        $radix = $fixture['radix_branch'];
        self::assertIsArray($radix);
        self::assertIsInt($radix['record_count']);
        self::assertIsInt($radix['value_bytes']);
        $records = [];
        for ($index = 0; $index < $radix['record_count']; ++$index) {
            $path = 'G::shared::row-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT);
            $records[] = [$path, UdbValue::string(str_repeat('v', $radix['value_bytes']))];
        }

        $summary = UdbLogicalDigest::summarize(UdbBlock::Lines, $records);
        self::assertSame($radix['record_count'], $summary['count']);
        self::assertGreaterThan(16_777_216, $summary['weight']);
        self::assertSame($radix['expected_digest'], $summary['digest']);
    }
}
