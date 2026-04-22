<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network\Event;

use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\Network\Event\ChannelJoinReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelKickReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelListModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelPartReceivedEvent;
use App\Infrastructure\IRC\Network\Event\ChannelTopicReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserHostReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserMetadataReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserModeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserNickChangeReceivedEvent;
use App\Infrastructure\IRC\Network\Event\UserQuitReceivedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChannelJoinReceivedEvent::class)]
#[CoversClass(ChannelKickReceivedEvent::class)]
#[CoversClass(ChannelListModeReceivedEvent::class)]
#[CoversClass(ChannelModeReceivedEvent::class)]
#[CoversClass(ChannelPartReceivedEvent::class)]
#[CoversClass(ChannelTopicReceivedEvent::class)]
#[CoversClass(UserHostReceivedEvent::class)]
#[CoversClass(UserMetadataReceivedEvent::class)]
#[CoversClass(UserModeReceivedEvent::class)]
#[CoversClass(UserNickChangeReceivedEvent::class)]
#[CoversClass(UserQuitReceivedEvent::class)]
final class InfrastructureEventTest extends TestCase
{
    #[Test]
    public function channelJoinReceivedEventStoresAllProperties(): void
    {
        $event = new ChannelJoinReceivedEvent(
            channelName: new ChannelName('#test'),
            timestamp: 1704067200,
            modeStr: '+nt',
            members: [['uid' => new Uid('001ABC'), 'role' => ChannelMemberRole::Op]],
            listModes: ['b' => ['*!*@bad.host']],
            modeParams: ['key123'],
        );

        self::assertSame('#test', $event->channelName->value);
        self::assertSame(1704067200, $event->timestamp);
        self::assertSame('+nt', $event->modeStr);
        self::assertCount(1, $event->members);
        self::assertSame('001ABC', $event->members[0]['uid']->value);
        self::assertSame(ChannelMemberRole::Op, $event->members[0]['role']);
        self::assertSame(['*!*@bad.host'], $event->listModes['b']);
        self::assertSame(['key123'], $event->modeParams);
    }

    #[Test]
    public function channelJoinReceivedEventDefaultsEmptyArrays(): void
    {
        $event = new ChannelJoinReceivedEvent(
            channelName: new ChannelName('#test'),
            timestamp: 1704067200,
            modeStr: '',
            members: [],
        );

        self::assertSame([], $event->listModes);
        self::assertSame([], $event->modeParams);
    }

    #[Test]
    public function channelKickReceivedEventStoresAllProperties(): void
    {
        $event = new ChannelKickReceivedEvent(
            channelName: new ChannelName('#chan'),
            targetId: '002DEF',
            reason: 'Kicked',
        );

        self::assertSame('#chan', $event->channelName->value);
        self::assertSame('002DEF', $event->targetId);
        self::assertSame('Kicked', $event->reason);
    }

    #[Test]
    public function channelListModeReceivedEventStoresAllProperties(): void
    {
        $event = new ChannelListModeReceivedEvent(
            channelName: new ChannelName('#chan'),
            modeChar: 'b',
            params: ['*!*@bad.host'],
        );

        self::assertSame('#chan', $event->channelName->value);
        self::assertSame('b', $event->modeChar);
        self::assertSame(['*!*@bad.host'], $event->params);
    }

    #[Test]
    public function channelModeReceivedEventStoresAllProperties(): void
    {
        $event = new ChannelModeReceivedEvent(
            channelName: new ChannelName('#chan'),
            modeStr: '+o',
            modeParams: ['001ABC'],
        );

        self::assertSame('#chan', $event->channelName->value);
        self::assertSame('+o', $event->modeStr);
        self::assertSame(['001ABC'], $event->modeParams);
    }

    #[Test]
    public function channelModeReceivedEventDefaultsEmptyModeParams(): void
    {
        $event = new ChannelModeReceivedEvent(
            channelName: new ChannelName('#chan'),
            modeStr: '+nt',
        );

        self::assertSame([], $event->modeParams);
    }

    #[Test]
    public function channelPartReceivedEventStoresAllProperties(): void
    {
        $event = new ChannelPartReceivedEvent(
            sourceId: '001ABC',
            channelName: new ChannelName('#test'),
            reason: 'Bye',
            wasKicked: true,
        );

        self::assertSame('001ABC', $event->sourceId);
        self::assertSame('#test', $event->channelName->value);
        self::assertSame('Bye', $event->reason);
        self::assertTrue($event->wasKicked);
    }

    #[Test]
    public function channelPartReceivedEventDefaultsWasKickedFalse(): void
    {
        $event = new ChannelPartReceivedEvent(
            sourceId: '001ABC',
            channelName: new ChannelName('#test'),
            reason: 'Bye',
        );

        self::assertFalse($event->wasKicked);
    }

    #[Test]
    public function channelTopicReceivedEventStoresAllProperties(): void
    {
        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
            topic: 'Welcome',
            setterNick: 'OpNick',
            sourceUid: '994AAAGUW',
        );

        self::assertSame('#test', $event->channelName->value);
        self::assertSame('Welcome', $event->topic);
        self::assertSame('OpNick', $event->setterNick);
        self::assertSame('994AAAGUW', $event->sourceUid);
    }

    #[Test]
    public function channelTopicReceivedEventDefaultsNullOptionalProperties(): void
    {
        $event = new ChannelTopicReceivedEvent(
            channelName: new ChannelName('#test'),
            topic: 'Welcome',
        );

        self::assertNull($event->setterNick);
        self::assertNull($event->sourceUid);
    }

    #[Test]
    public function userHostReceivedEventStoresAllProperties(): void
    {
        $event = new UserHostReceivedEvent(
            sourceId: '001ABC',
            newHost: 'new.host.name',
        );

        self::assertSame('001ABC', $event->sourceId);
        self::assertSame('new.host.name', $event->newHost);
    }

    #[Test]
    public function userMetadataReceivedEventStoresAllProperties(): void
    {
        $event = new UserMetadataReceivedEvent(
            targetUid: '001ABC',
            key: 'accountname',
            value: 'TestAccount',
        );

        self::assertSame('001ABC', $event->targetUid);
        self::assertSame('accountname', $event->key);
        self::assertSame('TestAccount', $event->value);
    }

    #[Test]
    public function userModeReceivedEventStoresAllProperties(): void
    {
        $event = new UserModeReceivedEvent(
            sourceId: '001ABC',
            modeStr: '+i',
        );

        self::assertSame('001ABC', $event->sourceId);
        self::assertSame('+i', $event->modeStr);
    }

    #[Test]
    public function userNickChangeReceivedEventStoresAllProperties(): void
    {
        $event = new UserNickChangeReceivedEvent(
            sourceId: '001ABC',
            newNickStr: 'NewNick',
        );

        self::assertSame('001ABC', $event->sourceId);
        self::assertSame('NewNick', $event->newNickStr);
    }

    #[Test]
    public function userQuitReceivedEventStoresAllProperties(): void
    {
        $event = new UserQuitReceivedEvent(
            sourceId: '001ABC',
            reason: 'Leaving',
        );

        self::assertSame('001ABC', $event->sourceId);
        self::assertSame('Leaving', $event->reason);
    }
}
