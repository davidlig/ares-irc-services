<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\Model;

use App\ChanServ\Application\Model\ChannelEntryMember;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelEntryMember::class)]
final class ChannelEntryMemberTest extends TestCase
{
    #[Test]
    public function getVisibleMaskReturnsDisplayMaskWhenProvided(): void
    {
        $member = new ChannelEntryMember(
            'UID1',
            'Alice',
            'Alice!ident@real.host.net',
            true,
            false,
            false,
            'Alice!ident@cloaked.net',
        );

        self::assertSame('UID1', $member->uid);
        self::assertSame('Alice', $member->nickname);
        self::assertSame('Alice!ident@real.host.net', $member->userMask);
        self::assertTrue($member->identified);
        self::assertFalse($member->operator);
        self::assertFalse($member->service);
        self::assertSame('Alice!ident@cloaked.net', $member->displayMask);
        self::assertSame('Alice!ident@cloaked.net', $member->getVisibleMask());
    }

    #[Test]
    public function getVisibleMaskFallsBackToUserMaskWhenDisplayMaskIsNull(): void
    {
        $member = new ChannelEntryMember(
            'UID2',
            'Bob',
            'Bob!ident@real.host.net',
            false,
            true,
            true,
        );

        self::assertNull($member->displayMask);
        self::assertSame('Bob!ident@real.host.net', $member->getVisibleMask());
    }
}
