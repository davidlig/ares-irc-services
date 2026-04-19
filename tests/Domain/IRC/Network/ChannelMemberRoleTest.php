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
    public function highestRoleFromLetters(): void
    {
        self::assertSame(ChannelMemberRole::Owner, ChannelMemberRole::highestRoleFromLetters(['v', 'q']));
        self::assertSame(ChannelMemberRole::Op, ChannelMemberRole::highestRoleFromLetters(['v', 'o']));
        self::assertSame(ChannelMemberRole::None, ChannelMemberRole::highestRoleFromLetters([]));
        self::assertSame(ChannelMemberRole::Voice, ChannelMemberRole::highestRoleFromLetters(['v']));
        self::assertSame(ChannelMemberRole::None, ChannelMemberRole::highestRoleFromLetters(['x']));
    }

    #[Test]
    public function labelReturnsReadableName(): void
    {
        self::assertSame('owner', ChannelMemberRole::Owner->label());
        self::assertSame('admin', ChannelMemberRole::Admin->label());
        self::assertSame('op', ChannelMemberRole::Op->label());
        self::assertSame('halfop', ChannelMemberRole::HalfOp->label());
        self::assertSame('voice', ChannelMemberRole::Voice->label());
        self::assertSame('none', ChannelMemberRole::None->label());
    }

    #[Test]
    public function fromModeLetterMapsHalfOpAndAdmin(): void
    {
        self::assertSame(ChannelMemberRole::HalfOp, ChannelMemberRole::fromModeLetter('h'));
        self::assertSame(ChannelMemberRole::Admin, ChannelMemberRole::fromModeLetter('a'));
    }

    #[Test]
    public function enumValuesAreAbstractLabels(): void
    {
        self::assertSame('voice', ChannelMemberRole::Voice->value);
        self::assertSame('halfop', ChannelMemberRole::HalfOp->value);
        self::assertSame('op', ChannelMemberRole::Op->value);
        self::assertSame('admin', ChannelMemberRole::Admin->value);
        self::assertSame('owner', ChannelMemberRole::Owner->value);
        self::assertSame('', ChannelMemberRole::None->value);
    }
}
