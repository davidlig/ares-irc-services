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
 * batch per-channel work across the whole split. Each quit is isolated: a failing quit listener is
 * logged and the remaining split users still quit, and the failing user itself is still removed from
 * the network state by the enricher, so no ghost users stay. A failing started listener is logged
 * the same way and does not stop the quits. The finished event is published once at the end, even
 * when a started listener failed after it already began its batch; a started failure is logged before
 * the quits run, so a later finished failure cannot hide it.
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

        try {
            try {
                $this->eventDispatcher->dispatch(new ServerDelinkStartedEvent($event->serverSid));
            } catch (\Throwable $exception) {
                $this->logger->error(sprintf(
                    'Delink start listener for server %s failed; quitting its users anyway.',
                    $event->serverSid,
                ), ['exception' => $exception]);
            }

            foreach ($affected as $user) {
                try {
                    $this->eventDispatcher->dispatch(new UserQuitReceivedEvent($user->uid->value, $reason));
                } catch (\Throwable $exception) {
                    $this->logger->error(sprintf(
                        'Split quit for %s (%s) failed; continuing with the remaining users of server %s.',
                        $user->uid->value,
                        $user->getNick()->value,
                        $event->serverSid,
                    ), ['exception' => $exception]);
                }
            }
        } finally {
            $this->eventDispatcher->dispatch(new ServerDelinkFinishedEvent($event->serverSid));
        }
    }
}
