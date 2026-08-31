<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Protocol\UnrealUdb\Protocol;

use App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol\UdbBlock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UdbBlock::class)]
final class UdbBlockTest extends TestCase
{
    #[Test]
    public function lettersMatchWireIdentifier(): void
    {
        self::assertSame('N', UdbBlock::Nicks->letter());
        self::assertSame('C', UdbBlock::Channels->letter());
        self::assertSame('I', UdbBlock::Ips->letter());
        self::assertSame('S', UdbBlock::Settings->letter());
        self::assertSame('L', UdbBlock::Links->letter());
        self::assertSame('K', UdbBlock::Lines->letter());
    }

    #[Test]
    public function fromLetterResolvesAllBlocks(): void
    {
        foreach (UdbBlock::all() as $block) {
            self::assertSame($block, UdbBlock::fromLetter($block->letter()));
        }
    }

    #[Test]
    public function fromLetterReturnsNullForUnknownLetter(): void
    {
        self::assertNull(UdbBlock::fromLetter('X'));
        self::assertNull(UdbBlock::fromLetter('n'));
    }

    #[Test]
    public function allReturnsSixBlocksInReconciliationOrder(): void
    {
        self::assertCount(6, UdbBlock::all());
        self::assertSame('NCISLK', implode('', array_map(static fn (UdbBlock $b) => $b->letter(), UdbBlock::all())));
    }
}
