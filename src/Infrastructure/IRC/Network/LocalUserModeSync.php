<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Irc\Domain\Event\UserModeChangedEvent;
use App\Irc\Domain\LocalUserModeSyncInterface;
use App\Irc\Domain\ValueObject\Uid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Applies a user mode delta to the local network state by dispatching
 * UserModeChangedEvent. NetworkStateSubscriber applies the delta to the
 * NetworkUser in the repository.
 */
final readonly class LocalUserModeSync implements LocalUserModeSyncInterface
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    public function apply(Uid $uid, string $modeDelta): void
    {
        $this->eventDispatcher->dispatch(new UserModeChangedEvent($uid, $modeDelta));
    }
}
