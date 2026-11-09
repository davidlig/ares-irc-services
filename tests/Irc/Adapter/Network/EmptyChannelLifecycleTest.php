<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\ChannelModeStateSynchronizer;
use App\Irc\Adapter\Network\ChannelSyncCompletedRegistry;
use App\Irc\Adapter\Network\Event\ChannelJoinReceivedEvent;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Network\NetworkStateSubscriber;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Domain\Event\UserLeftChannelEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

#[CoversClass(NetworkStateSubscriber::class)]
#[CoversClass(NetworkEventEnricher::class)]
#[CoversClass(ChannelModeStateSynchronizer::class)]
#[CoversClass(ChannelSyncCompletedRegistry::class)]
#[CoversClass(InMemoryChannelRepository::class)]
final class EmptyChannelLifecycleTest extends TestCase
{
    #[Test]
    public function departureKeepsChannelAndMarkerWhenAnotherMemberRemains(): void
    {
        $channels = new InMemoryChannelRepository();
        $registry = new ChannelSyncCompletedRegistry();
        $name = new ChannelName('#test');
        $uid = new Uid('001ABC123');
        $other = new Uid('001ABC124');
        $channel = new Channel($name, '+nt', new DateTimeImmutable('@100'));
        $channel->syncMember($uid, ChannelMemberRole::Op);
        $channel->syncMember($other, ChannelMemberRole::Voice);
        $channels->save($channel);
        $registry->markSyncCompleted('#test');
        $timestamp = $registry->getSyncCompletedAt('#test');
        $subscriber = new NetworkStateSubscriber($this->createStub(NetworkUserRepositoryInterface::class), $channels, $registry);

        $subscriber->onUserLeftChannel(new UserLeftChannelEvent($uid, new Nick('TestUser'), $name, 'Part', false));

        self::assertSame($channel, $channels->findByName($name));
        self::assertFalse($channel->isMember($uid));
        self::assertTrue($channel->isMember($other));
        self::assertSame($timestamp, $registry->getSyncCompletedAt('#test'));
    }

    #[Test]
    public function recreatedChannelUsesItsNewTimestampModesAndPrefixes(): void
    {
        $channels = new InMemoryChannelRepository();
        $users = $this->createStub(NetworkUserRepositoryInterface::class);
        $registry = new ChannelSyncCompletedRegistry();
        $name = new ChannelName('#test');
        $uid = new Uid('001ABC123');
        $old = new Channel($name, '+s', new DateTimeImmutable('@100'));
        $old->syncMember($uid, ChannelMemberRole::Op);
        $channels->save($old);
        $registry->markSyncCompleted('#test');

        $subscriber = new NetworkStateSubscriber($users, $channels, $registry);
        $subscriber->onUserLeftChannel(new UserLeftChannelEvent($uid, new Nick('TestUser'), $name, 'Part', false));
        self::assertSame(0, $channels->count());
        self::assertFalse($registry->isSyncCompleted('#test'));

        $enricher = new NetworkEventEnricher(
            $channels,
            $users,
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(SkipIdentifiedModeStripRegistry::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
        );
        $enricher->onChannelJoinReceived(new ChannelJoinReceivedEvent($name, 200, '+nt', [
            ['uid' => $uid, 'role' => ChannelMemberRole::Op, 'prefixLetters' => ['o']],
        ]));

        $channel = $channels->findByName($name);
        self::assertNotNull($channel);
        self::assertSame(200, $channel->getCreatedAt()->getTimestamp());
        self::assertSame('+nt', $channel->getModes());
        $member = $channel->getMember($uid);
        self::assertNotNull($member);
        self::assertSame(ChannelMemberRole::Op, $member->role);
        self::assertSame(['o'], $member->prefixLetters);
    }
}
