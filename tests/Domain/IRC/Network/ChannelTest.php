<?php

declare(strict_types=1);

namespace App\Tests\Domain\IRC\Network;

use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Channel::class)]
final class ChannelTest extends TestCase
{
    #[Test]
    public function newChannelHasNoMembersAndDefaultState(): void
    {
        $name = new ChannelName('#test');
        $channel = new Channel($name);

        self::assertSame('', $channel->getModes());
        self::assertSame(0, $channel->getMemberCount());
        self::assertNull($channel->getTopic());
        self::assertSame([], $channel->getBans());
        self::assertSame([], $channel->getExempts());
        self::assertSame([], $channel->getInviteExceptions());

        $data = $channel->toArray();
        self::assertSame('#test', $data['name']);
        self::assertSame('', $data['modes']);
        self::assertNull($data['topic']);
        self::assertSame(0, $data['members']);
        self::assertNotEmpty($data['createdAt']);
        self::assertNotFalse(DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $data['createdAt']));
    }

    #[Test]
    public function syncMemberAndMembershipQueries(): void
    {
        $channel = new Channel(new ChannelName('#chan'));
        $uid = new Uid('AAA111');

        self::assertFalse($channel->isMember($uid));

        $channel->syncMember($uid, ChannelMemberRole::Voice);

        self::assertTrue($channel->isMember($uid));
        self::assertSame(1, $channel->getMemberCount());
        self::assertCount(1, $channel->getMembers());
        self::assertNotNull($channel->getMember($uid));
    }

    #[Test]
    public function applyMemberPrefixChangeUpdatesRoleAndPrefixes(): void
    {
        $channel = new Channel(new ChannelName('#chan'));
        $uid = new Uid('AAA111');

        $channel->syncMember($uid, ChannelMemberRole::None, []);
        $channel->applyMemberPrefixChange($uid, 'v', true);

        $member = $channel->getMember($uid);
        self::assertNotNull($member);
        self::assertContains('v', $member->prefixLetters);

        $channel->applyMemberPrefixChange($uid, 'v', false);
        $member = $channel->getMember($uid);
        self::assertNotNull($member);
        self::assertNotContains('v', $member->prefixLetters);
    }

    #[Test]
    public function applyMemberPrefixChangeForUnknownUidDoesNothing(): void
    {
        $channel = new Channel(new ChannelName('#chan'));
        $uidIn = new Uid('AAA111');
        $uidUnknown = new Uid('BBB222');
        $channel->syncMember($uidIn, ChannelMemberRole::Op);

        $channel->applyMemberPrefixChange($uidUnknown, 'v', true);
        $channel->applyMemberPrefixChange($uidUnknown, 'o', false);

        self::assertTrue($channel->isMember($uidIn));
        self::assertFalse($channel->isMember($uidUnknown));
        $member = $channel->getMember($uidIn);
        self::assertNotNull($member);
        self::assertSame(ChannelMemberRole::Op, $member->role);
    }

    #[Test]
    public function removeMember(): void
    {
        $channel = new Channel(new ChannelName('#chan'));
        $uid = new Uid('AAA111');

        $channel->syncMember($uid, ChannelMemberRole::Voice);
        self::assertTrue($channel->isMember($uid));

        $channel->removeMember($uid);
        self::assertFalse($channel->isMember($uid));
        self::assertNull($channel->getMember($uid));
    }

    #[Test]
    public function modesAndModeParams(): void
    {
        $channel = new Channel(new ChannelName('#chan'), '+nt');

        self::assertSame('+nt', $channel->getModes());
        $channel->updateModes('+n');
        self::assertSame('+n', $channel->getModes());

        self::assertNull($channel->getModeParam('k'));
        $channel->applyModeParam('k', 'secret');
        self::assertSame('secret', $channel->getModeParam('k'));
        self::assertSame(['k' => 'secret'], $channel->getModeParams());

        $channel->clearModeParam('k');
        self::assertNull($channel->getModeParam('k'));
        self::assertSame([], $channel->getModeParams());
    }

    #[Test]
    public function createdAtCanBeUpdated(): void
    {
        $channel = new Channel(new ChannelName('#chan'));
        $original = $channel->getCreatedAt();
        $later = $original->modify('+1 hour');

        $channel->updateCreatedAt($later);

        self::assertNotSame($original, $channel->getCreatedAt());
        self::assertSame($later->getTimestamp(), $channel->getCreatedAt()->getTimestamp());
    }

    #[Test]
    public function topicAndListsManagement(): void
    {
        $channel = new Channel(new ChannelName('#chan'));

        $channel->updateTopic('Hello');
        self::assertSame('Hello', $channel->getTopic());

        $channel->addBan('bad!*@*');
        $channel->addBan('bad!*@*'); // duplicado ignorado
        self::assertSame(['bad!*@*'], $channel->getBans());

        $channel->removeBan('bad!*@*');
        self::assertSame([], $channel->getBans());

        $channel->addExempt('friend!*@*');
        self::assertSame(['friend!*@*'], $channel->getExempts());
        $channel->removeExempt('friend!*@*');
        self::assertSame([], $channel->getExempts());

        $channel->addInviteException('vip!*@*');
        self::assertSame(['vip!*@*'], $channel->getInviteExceptions());
        $channel->removeInviteException('vip!*@*');
        self::assertSame([], $channel->getInviteExceptions());
    }
}
