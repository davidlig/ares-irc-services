<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\FtopicReceivedEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When the channel topic changes on the wire (e.g. /topic):
 * - If TOPICLOCK is on and there is a stored topic: reapply the stored topic (lock) and do not persist the change.
 * - Otherwise: persist the new topic to RegisteredChannel and store setter nick (unless set by services).
 */
final readonly class ChanServTopicSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelServiceActionsPort $channelServiceActions,
        private string $chanservNick,
        private string $nickservNick,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FtopicReceivedEvent::class => ['onTopicReceived', 0],
        ];
    }

    public function onTopicReceived(FtopicReceivedEvent $event): void
    {
        $channelName = $event->channelName->value;
        $registered = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $registered) {
            return;
        }

        $storedTopic = $registered->getTopic();
        if ($registered->isTopicLock() && null !== $storedTopic) {
            $this->channelServiceActions->setChannelTopic($channelName, $storedTopic);
            $this->logger->debug('ChanServ TOPICLOCK: reapplied stored topic', ['channel' => $channelName]);

            return;
        }

        $topic = $event->topic;
        $setterNick = $event->setterNick;
        if (null !== $setterNick && $this->isServicesNick($setterNick)) {
            $setterNick = null;
        }

        $registered->setTopic($topic, $setterNick);
        $this->channelRepository->save($registered);
        $this->logger->debug('ChanServ synced topic to DB', ['channel' => $channelName]);
    }

    private function isServicesNick(string $nick): bool
    {
        $nickLower = strtolower($nick);

        return $nickLower === strtolower($this->chanservNick) || $nickLower === strtolower($this->nickservNick);
    }
}
