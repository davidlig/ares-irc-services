<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ChannelSyncCompletedRegistryInterface;
use App\Application\Port\UidResolverInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Infrastructure\IRC\Network\Event\ChannelTopicReceivedEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When the channel topic changes on the wire (e.g. /topic):
 * - If TOPICLOCK is on and there is a stored topic: reapply the stored topic (lock) and do not persist the change.
 * - Otherwise: persist the new topic to RegisteredChannel only after the channel sync has completed
 *   (+r, SECURE strip, MLOCK, topic apply), to avoid a user with temporary op overwriting stored topic.
 */
final readonly class ChanServTopicSyncSubscriber implements EventSubscriberInterface
{
    /** Grace period (seconds) after sync completed during which topic from wire is not persisted (avoids race). */
    private const float TOPIC_PERSIST_GRACE_SECONDS = 2.0;

    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelServiceActionsPort $channelServiceActions,
        private ChannelSyncCompletedRegistryInterface $syncCompletedRegistry,
        private UidResolverInterface $uidResolver,
        private string $chanservNick,
        private string $nickservNick,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelTopicReceivedEvent::class => ['onTopicReceived', 0],
        ];
    }

    public function onTopicReceived(ChannelTopicReceivedEvent $event): void
    {
        $channelName = $event->channelName->value;
        $registered = $this->channelRepository->findByChannelName(strtolower($channelName));
        if (null === $registered) {
            return;
        }

        if ($registered->isSuspended()) {
            return;
        }

        if ($registered->isTopicLock()) {
            $storedTopic = $registered->getTopic();
            $this->channelServiceActions->setChannelTopic($channelName, $storedTopic);
            $this->logger->debug('ChanServ TOPICLOCK: reapplied stored topic', ['channel' => $channelName]);

            return;
        }

        if (!$this->syncCompletedRegistry->isSyncCompleted($channelName)) {
            $this->logger->debug('ChanServ topic from wire not persisted (channel sync not yet completed)', ['channel' => $channelName]);

            return;
        }

        $syncCompletedAt = $this->syncCompletedRegistry->getSyncCompletedAt($channelName);
        if (null !== $syncCompletedAt && (microtime(true) - $syncCompletedAt) < self::TOPIC_PERSIST_GRACE_SECONDS) {
            $this->logger->debug('ChanServ topic from wire not persisted (within grace period after sync)', ['channel' => $channelName]);

            return;
        }

        $topic = $event->topic;
        $setterNick = $this->resolveSetterNick($event);

        $registered->updateTopic($topic, $setterNick);
        $this->channelRepository->save($registered);
        $this->logger->debug('ChanServ synced topic to DB', ['channel' => $channelName]);
    }

    private function resolveSetterNick(ChannelTopicReceivedEvent $event): ?string
    {
        $setterNick = $event->setterNick;

        if (null === $setterNick && null !== $event->sourceUid) {
            $setterNick = $this->uidResolver->resolveUidToNick($event->sourceUid);
        }

        if (null !== $setterNick && $this->isServicesNick($setterNick)) {
            return null;
        }

        return $setterNick;
    }

    private function isServicesNick(string $nick): bool
    {
        if (str_contains($nick, '.')) {
            return true;
        }

        $nickLower = strtolower($nick);

        return $nickLower === strtolower($this->chanservNick) || $nickLower === strtolower($this->nickservNick);
    }
}
