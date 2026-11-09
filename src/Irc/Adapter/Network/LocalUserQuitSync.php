<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\UserQuitReceivedEvent;
use App\Irc\Application\Port\In\LocalUserQuitSyncPort;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final readonly class LocalUserQuitSync implements LocalUserQuitSyncPort
{
    public function __construct(private EventDispatcherInterface $eventDispatcher) {}

    public function apply(string $uid, string $reason): void
    {
        $this->eventDispatcher->dispatch(new UserQuitReceivedEvent($uid, $reason));
    }
}
