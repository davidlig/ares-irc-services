<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Domain\IRC\Event\UserModeChangedEvent;
use App\Domain\IRC\LocalUserModeSyncInterface;
use App\Domain\IRC\ValueObject\Uid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Applies a user mode delta to the local network state by dispatching
 * UserModeChangedEvent. NetworkStateSubscriber applies the delta to the
 * NetworkUser in the repository.
 */
final readonly class LocalUserModeSync implements LocalUserModeSyncInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function apply(Uid $uid, string $modeDelta): void
    {
        $this->eventDispatcher->dispatch(new UserModeChangedEvent($uid, $modeDelta));
    }
}
