<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\Network;

use App\Irc\Adapter\Network\ChannelSyncCompletedRegistry;
use App\Irc\Adapter\Network\Event\UserQuitReceivedEvent;
use App\Irc\Adapter\Network\InMemoryChannelRepository;
use App\Irc\Adapter\Network\InMemoryNetworkUserRepository;
use App\Irc\Adapter\Network\NetworkEventEnricher;
use App\Irc\Adapter\Network\NetworkStateSubscriber;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\SkipIdentifiedModeStripRegistry;
use App\Irc\Domain\Event\UserLeftChannelEvent;
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
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Throwable;

/**
 * A quit whose channel-departure listener throws (for example a ChanServ listener hitting a failed
 * database) still removes the user from the network state and publishes the quit, so the user
 * never stays as a ghost; the departure failure still propagates to the caller.
 */
#[CoversClass(NetworkEventEnricher::class)]
final class QuitDepartureFailureIntegrationTest extends TestCase
{
    private const string QUITTER = '001AAAAAA';

    private const string WITNESS = '001BBBBBB';

    #[Test]
    public function throwingDepartureListenerDoesNotLeaveTheQuittingUserAsAGhost(): void
    {
        $users = new InMemoryNetworkUserRepository();
        $channels = new InMemoryChannelRepository();
        $dispatcher = new EventDispatcher();
        $logger = $this->createMock(LoggerInterface::class);
        $dispatcher->addSubscriber(new NetworkStateSubscriber($users, $channels, new ChannelSyncCompletedRegistry()));
        $dispatcher->addSubscriber(new NetworkEventEnricher(
            $channels,
            $users,
            $dispatcher,
            $this->createStub(SkipIdentifiedModeStripRegistry::class),
            $this->createStub(ActiveChannelModeSupportProviderInterface::class),
            $this->createStub(ActiveProtocolModuleHolderInterface::class),
            $logger,
        ));

        $quitter = $this->createUser(self::QUITTER, 'Quitter');
        $witness = $this->createUser(self::WITNESS, 'Witness');
        $users->add($quitter);
        $users->add($witness);
        foreach (['#first', '#second', '#third'] as $name) {
            $channel = new Channel(new ChannelName($name), '+nt', new DateTimeImmutable('@1700000000'));
            $channel->syncMember($quitter->uid, ChannelMemberRole::None);
            $channel->syncMember($witness->uid, ChannelMemberRole::None);
            $channels->save($channel);
            $quitter->addChannel($channel->name);
            $witness->addChannel($channel->name);
        }

        $firstFailure = new RuntimeException('departure listener failed on #first');
        $secondFailure = new RuntimeException('departure listener failed on #third');
        $departures = [];
        $quits = [];
        // Same priority as the published-event bridge, so it runs before the network state update.
        $dispatcher->addListener(UserLeftChannelEvent::class, static function (UserLeftChannelEvent $event) use (&$departures, $firstFailure, $secondFailure): void {
            $departures[] = $event->channel->value;
            if ('#first' === $event->channel->value) {
                throw $firstFailure;
            }
            if ('#third' === $event->channel->value) {
                throw $secondFailure;
            }
        }, 10);
        $dispatcher->addListener(UserQuitNetworkEvent::class, static function (UserQuitNetworkEvent $event) use (&$quits): void {
            $quits[] = $event->uid->value;
        }, -10);
        $logger->expects(self::once())->method('error')->with(
            'Channel departure of 001AAAAAA (Quitter) from #third failed during quit.',
            ['exception' => $secondFailure],
        );

        $caught = null;
        try {
            $dispatcher->dispatch(new UserQuitReceivedEvent(self::QUITTER, 'Bye'));
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        self::assertSame($firstFailure, $caught);
        self::assertSame(['#first', '#second', '#third'], $departures);
        self::assertSame([self::QUITTER], $quits);
        self::assertNull($users->findByUid(new Uid(self::QUITTER)));
        self::assertNull($users->findByNick(new Nick('Quitter')));
        foreach (['#first', '#second', '#third'] as $name) {
            $channel = $channels->findByName(new ChannelName($name));
            self::assertNotNull($channel, $name);
            self::assertFalse($channel->isMember($quitter->uid), $name);
            self::assertTrue($channel->isMember($witness->uid), $name);
        }
    }

    private function createUser(string $uid, string $nick): NetworkUser
    {
        return new NetworkUser(
            new Uid($uid),
            new Nick($nick),
            new Ident('user'),
            'hostname.example.com',
            'cloaked.example.com',
            'vhost.example.com',
            '+i',
            new DateTimeImmutable(),
            'Real Name',
            '001',
            'base64ip',
        );
    }
}
