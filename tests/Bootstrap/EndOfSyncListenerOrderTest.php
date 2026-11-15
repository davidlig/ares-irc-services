<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\Irc\Adapter\Event\NetworkSyncCompleteEvent;
use Closure;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

use function is_array;
use function is_object;
use function sprintf;

/**
 * The remote-burst end is published before every other end-of-sync listener, so a failing listener
 * cannot keep the ChanServ remote-burst window open. That is only safe while none of the later
 * end-of-sync listeners produces a member join or channel synchronization, the two events the window
 * defers: joins and synchronizations come only from inbound JOIN/SJOIN/FJOIN lines parsed by the
 * network state adapters, while these listeners only send outbound lines (kicks, modes, service
 * joins registered directly in the network state) or touch UDB storage.
 *
 * Adding, removing or reordering an end-of-sync listener changes this list: re-audit that the new
 * listener does not dispatch UserJoinedChannelEvent or ChannelSynchronizedEvent before updating it.
 */
#[CoversNothing]
final class EndOfSyncListenerOrderTest extends KernelTestCase
{
    #[Test]
    public function auditedEndOfSyncListenersRunAfterTheRemoteBurstEnd(): void
    {
        self::bootKernel();
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        $listeners = [];
        foreach ($dispatcher->getListeners(NetworkSyncCompleteEvent::class) as $listener) {
            self::assertIsCallable($listener);
            $listeners[] = sprintf(
                '%s::%s@%d',
                self::listenerClass($listener),
                is_array($listener) ? $listener[1] : '__invoke',
                $dispatcher->getListenerPriority(NetworkSyncCompleteEvent::class, $listener) ?? 0,
            );
        }

        self::assertSame([
            'App\Irc\Adapter\In\Event\PublishedIrcEventBridge::publishRemoteBurstEnded@2048',
            'App\Irc\Adapter\In\Event\IrcopsDebugChannelProtectionSubscriber::onSyncComplete@15',
            'App\Irc\Adapter\Protocol\UnrealUdb\Persistence\UdbStoreInitializer::onNetworkSyncComplete@0',
            'App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbChannelSyncSubscriber::onNetworkSyncComplete@-5',
            'App\Irc\Adapter\Protocol\UnrealUdb\Synchronization\UdbPasswordRepairSubscriber::onNetworkSyncComplete@-10',
            'App\Irc\Adapter\In\Event\DebugChannelJoinSubscriber::onSyncComplete@-30',
            'App\Irc\Adapter\In\Event\PublishedIrcEventBridge::publishNetworkSynchronizationCompleted@-256',
        ], $listeners);
    }

    private static function listenerClass(callable $listener): string
    {
        if (is_array($listener) && is_object($listener[0])) {
            return $listener[0]::class;
        }

        return $listener instanceof Closure ? Closure::class : 'unknown';
    }
}
