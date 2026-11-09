<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\LocalUserQuitSync;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Network\NetworkStateSubscriber;
use App\Irc\Adapter\Protocol\InspIRCd\InspIRCdNetworkStateAdapter;
use App\Irc\Adapter\Protocol\IRCMessage;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Domain\Event\UserQuitNetworkEvent;
use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\Network\ChannelMemberRole;
use App\Irc\Domain\Network\NetworkUser;
use App\Irc\Domain\ValueObject\ChannelName;
use App\Irc\Domain\ValueObject\Ident;
use App\Irc\Domain\ValueObject\Nick;
use App\Irc\Domain\ValueObject\Uid;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(LocalUserQuitSync::class)]
#[CoversClass(InspIRCdNetworkStateAdapter::class)]
#[CoversClass(NetworkEventEnricher::class)]
#[CoversClass(NetworkStateSubscriber::class)]
#[CoversClass(InMemoryNetworkUserRepository::class)]
#[CoversClass(InMemoryChannelRepository::class)]
final class KillCleanupTest extends TestCase
{
    #[Test]
    public function localKillAndEchoedQuitCleanStateOnce(): void
    {
        $users = new InMemoryNetworkUserRepository();
        $channels = new InMemoryChannelRepository();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new NetworkStateSubscriber($users, $channels));
        $dispatcher->addSubscriber(new NetworkEventEnricher($channels, $users, $dispatcher, $this->createStub(SkipIdentifiedModeStripRegistry::class), $this->createStub(ActiveChannelModeSupportProviderInterface::class), $this->createStub(ActiveProtocolModuleHolderInterface::class)));
        $uid = new Uid('001ABC123');
        $nick = new Nick('Example');
        $name = new ChannelName('#test');
        $user = new NetworkUser($uid, $nick, new Ident('ident'), 'host.example', 'cloak.example', 'vhost.example', '+i', new DateTimeImmutable(), 'Real', '001', '*');
        $user->addChannel($name);
        $users->add($user);
        $channel = new Channel($name, '', new DateTimeImmutable());
        $channel->syncMember($uid, ChannelMemberRole::None);
        $channels->save($channel);
        $quits = 0;
        $dispatcher->addListener(UserQuitNetworkEvent::class, static function () use (&$quits): void { ++$quits; });
        new LocalUserQuitSync($dispatcher)->apply($uid->value, 'reason');
        new InspIRCdNetworkStateAdapter($dispatcher)->handleMessage(new IRCMessage('QUIT', $uid->value, [], 'reason'));
        self::assertNull($users->findByUid($uid));
        self::assertNull($users->findByNick($nick));
        self::assertNull($channel->getMember($uid));
        self::assertSame(1, $quits);
    }
}
