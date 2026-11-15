<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Network;

use App\Irc\Adapter\Network\Event\UserQuitReceivedEvent;
use App\Irc\Application\PublishedEvent\ServerDelinkFinishedEvent;
use App\Irc\Application\PublishedEvent\ServerDelinkStartedEvent;
use App\Irc\Domain\Event\ServerDelinkedEvent;
use App\Irc\Domain\Repository\NetworkUserRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function count;
use function sprintf;

/**
 * When a server is delinked (SQUIT), treats all users on that server as quit:
 * dispatches UserQuitReceivedEvent for each so they follow the same enrichment
 * path as QUIT and KILL (per-channel UserLeftChannelEvent, repository removal and
 * UserQuitNetworkEvent for NickServ session cleanup).
 *
 * The quits are bracketed by ServerDelinkStartedEvent and ServerDelinkFinishedEvent, so services can
 * batch per-channel work across the whole split; the finished event is published even when a quit
 * listener fails.
 */
final readonly class ServerDelinkedSubscriber implements EventSubscriberInterface
{
    private const string DEFAULT_REASON = '*.net *.split';

    public function __construct(
        private NetworkUserRepositoryInterface $userRepository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ServerDelinkedEvent::class => ['onServerDelinked', 0],
        ];
    }

    public function onServerDelinked(ServerDelinkedEvent $event): void
    {
        $affected = $this->userRepository->findByServerSids($event->serverSids);

        if ([] === $affected) {
            return;
        }

        $reason = '' !== $event->reason ? $event->reason : self::DEFAULT_REASON;
        $this->logger->info(sprintf(
            'Server %s delinked (%d user(s)); dispatching quit events.',
            $event->serverSid,
            count($affected),
        ));

        $this->eventDispatcher->dispatch(new ServerDelinkStartedEvent($event->serverSid));
        try {
            foreach ($affected as $user) {
                $this->eventDispatcher->dispatch(new UserQuitReceivedEvent($user->uid->value, $reason));
            }
        } finally {
            $this->eventDispatcher->dispatch(new ServerDelinkFinishedEvent($event->serverSid));
        }
    }
}
