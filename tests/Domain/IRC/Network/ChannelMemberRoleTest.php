<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Network;

use App\Domain\IRC\Network\ChannelMemberRole;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelMemberRole::class)]
final class ChannelMemberRoleTest extends TestCase
{
    #[Test]
    public function fromSjoinPrefixMapsAllPrefixes(): void
    {
        self::assertSame(ChannelMemberRole::Voice, ChannelMemberRole::fromSjoinPrefix('+'));
        self::assertSame(ChannelMemberRole::HalfOp, ChannelMemberRole::fromSjoinPrefix('%'));
        self::assertSame(ChannelMemberRole::Op, ChannelMemberRole::fromSjoinPrefix('@'));
        self::assertSame(ChannelMemberRole::Admin, ChannelMemberRole::fromSjoinPrefix('~'));
        self::assertSame(ChannelMemberRole::Owner, ChannelMemberRole::fromSjoinPrefix('*'));
        self::assertSame(ChannelMemberRole::None, ChannelMemberRole::fromSjoinPrefix('x'));
    }

    #[Test]
    public function fromModeLetterMapsCorrectly(): void
    {
        self::assertSame(ChannelMemberRole::Voice, ChannelMemberRole::fromModeLetter('v'));
        self::assertSame(ChannelMemberRole::Op, ChannelMemberRole::fromModeLetter('o'));
        self::assertSame(ChannelMemberRole::Owner, ChannelMemberRole::fromModeLetter('q'));
        self::assertNull(ChannelMemberRole::fromModeLetter('x'));
    }

    #[Test]
    public function toModeLetter(): void
    {
        self::assertSame('v', ChannelMemberRole::Voice->toModeLetter());
        self::assertSame('o', ChannelMemberRole::Op->toModeLetter());
        self::assertSame('q', ChannelMemberRole::Owner->toModeLetter());
        self::assertSame('', ChannelMemberRole::None->toModeLetter());
    }

    #[Test]
    public function fromSjoinEntryToLettersConsumesPrefix(): void
    {
        $entry = '+@user';
        $letters = ChannelMemberRole::fromSjoinEntryToLetters($entry);

        self::assertSame(['v', 'o'], $letters);
        self::assertSame('user', $entry);
    }

    #[Test]
    public function fromSjoinEntryReturnsHighestRole(): void
    {
        $entry = '+@user';
        $role = ChannelMemberRole::fromSjoinEntry($entry);

        self::assertSame(ChannelMemberRole::Op, $role);
        self::assertSame('user', $entry);
    }

    #[Test]
    public function highestRoleFromLetters(): void
    {
        self::assertSame(ChannelMemberRole::Owner, ChannelMemberRole::highestRoleFromLetters(['v', 'q']));
        self::assertSame(ChannelMemberRole::Op, ChannelMemberRole::highestRoleFromLetters(['v', 'o']));
        self::assertSame(ChannelMemberRole::None, ChannelMemberRole::highestRoleFromLetters([]));
    }

    #[Test]
    public function labelReturnsReadableName(): void
    {
        self::assertSame('owner', ChannelMemberRole::Owner->label());
        self::assertSame('op', ChannelMemberRole::Op->label());
        self::assertSame('none', ChannelMemberRole::None->label());
    }
}
