<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Network;

use App\ChanServ\Adapter\Out\Network\ChannelRankModeMapper;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelRankModeMapper::class)]
final class ChannelRankModeMapperTest extends TestCase
{
    #[Test]
    #[DataProvider('rankModes')]
    public function itMapsRankModesInBothDirections(string $letter, ChannelRank $rank): void
    {
        $mapper = new ChannelRankModeMapper();

        self::assertSame($rank, $mapper->fromLetter($letter));
        self::assertSame($letter, $mapper->toLetter($rank));
    }

    /** @return iterable<string, array{string, ChannelRank}> */
    public static function rankModes(): iterable
    {
        yield 'owner' => ['q', ChannelRank::Owner];
        yield 'administrator' => ['a', ChannelRank::Administrator];
        yield 'operator' => ['o', ChannelRank::Operator];
        yield 'half operator' => ['h', ChannelRank::HalfOperator];
        yield 'voice' => ['v', ChannelRank::Voice];
    }

    #[Test]
    public function itRejectsUnknownAndWrongCaseLetters(): void
    {
        $mapper = new ChannelRankModeMapper();

        self::assertNull($mapper->fromLetter('x'));
        self::assertNull($mapper->fromLetter('O'));
    }
}
