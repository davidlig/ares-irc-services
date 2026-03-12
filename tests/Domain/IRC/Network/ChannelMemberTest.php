<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Network;

use App\Domain\IRC\Network\ChannelMember;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\Uid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelMember::class)]
final class ChannelMemberTest extends TestCase
{
    #[Test]
    public function constructorDerivesPrefixFromRole(): void
    {
        $uid = new Uid('AAA111');
        $member = new ChannelMember($uid, ChannelMemberRole::Op);

        self::assertSame($uid, $member->uid);
        self::assertSame(ChannelMemberRole::Op, $member->role);
        self::assertSame(['o'], $member->prefixLetters);
    }

    #[Test]
    public function constructorWithExplicitPrefixLetters(): void
    {
        $uid = new Uid('AAA111');
        $member = new ChannelMember($uid, ChannelMemberRole::Voice, ['v', 'o']);

        self::assertSame(['v', 'o'], $member->prefixLetters);
    }

    #[Test]
    public function withRoleReturnsNewInstance(): void
    {
        $uid = new Uid('AAA111');
        $member = new ChannelMember($uid, ChannelMemberRole::Voice);
        $updated = $member->withRole(ChannelMemberRole::Op);

        self::assertSame(ChannelMemberRole::Voice, $member->role);
        self::assertSame(ChannelMemberRole::Op, $updated->role);
        self::assertNotSame($member, $updated);
    }

    #[Test]
    public function withPrefixLettersReturnsNewInstance(): void
    {
        $uid = new Uid('AAA111');
        $member = new ChannelMember($uid, ChannelMemberRole::Op);
        $updated = $member->withPrefixLetters(['o', 'v']);

        self::assertSame(['o', 'v'], $updated->prefixLetters);
        self::assertNotSame($member, $updated);
    }
}
