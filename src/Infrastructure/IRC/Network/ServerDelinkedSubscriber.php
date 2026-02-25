<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Domain\IRC\Event\ServerDelinkedEvent;
use App\Domain\IRC\Event\UserQuitNetworkEvent;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function sprintf;

/**
 * When a server is delinked (SQUIT), treats all users on that server as quit:
 * dispatches UserQuitNetworkEvent for each so NetworkStateSubscriber and
 * NickProtectionService clean repos and IdentifiedSessionRegistry.
 */
final readonly class ServerDelinkedSubscriber implements EventSubscriberInterface
{
    private const string DEFAULT_REASON = '*.net *.split';

    public function __construct(
        private readonly NetworkUserRepositoryInterface $userRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ServerDelinkedEvent::class => ['onServerDelinked', 0],
        ];
    }

    public function onServerDelinked(ServerDelinkedEvent $event): void
    {
        $users = $this->userRepository->all();
        $affected = [];
        foreach ($users as $user) {
            if ($user->serverSid === $event->serverSid) {
                $affected[] = $user;
            }
        }

        if ([] === $affected) {
            return;
        }

        $reason = '' !== $event->reason ? $event->reason : self::DEFAULT_REASON;
        $this->logger->info(sprintf(
            'Server %s delinked (%d user(s)); dispatching quit events.',
            $event->serverSid,
            count($affected),
        ));

        foreach ($affected as $user) {
            $this->eventDispatcher->dispatch(new UserQuitNetworkEvent(
                uid: $user->uid,
                nick: $user->getNick(),
                reason: $reason,
                ident: $user->ident->value,
                displayHost: $user->getDisplayHost(),
            ));
        }
    }
}
