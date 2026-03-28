<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelAkickRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Event\NickDropEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * When a nick is dropped, clean up ChanServ data:
 * - Remove ACCESS entries for the nick (CASCADE DELETE)
 * - Clear creator reference in AKICK entries (SET NULL)
 * - Clear successor references where nick was successor (SET NULL)
 * - Handle channels where nick was founder (TRANSFER to successor or DROP channel).
 */
final readonly class ChanServNickDropCleanupSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelAccessRepositoryInterface $channelAccessRepository,
        private ChannelAkickRepositoryInterface $channelAkickRepository,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NickDropEvent::class => ['onNickDrop', 0],
        ];
    }

    public function onNickDrop(NickDropEvent $event): void
    {
        $nickId = $event->nickId;

        // 1. CASCADE DELETE: Remove all ACCESS entries for this nick
        $this->channelAccessRepository->deleteByNickId($nickId);

        // 2. SET NULL: Clear creator reference in AKICK entries
        $this->channelAkickRepository->clearCreatorNickId($nickId);

        // 3. SET NULL: Clear successor references (where nick was successor)
        $this->channelRepository->clearSuccessorNickId($nickId);

        // 4. Handle channels where nick was founder
        $founderChannels = $this->channelRepository->findByFounderNickId($nickId);
        foreach ($founderChannels as $channel) {
            $this->handleFounderDrop($channel);
        }
    }

    private function handleFounderDrop(RegisteredChannel $channel): void
    {
        $successorNickId = $channel->getSuccessorNickId();

        if (null !== $successorNickId) {
            // TRANSFER: Successor becomes new founder
            $channel->changeFounder($successorNickId);
            $this->channelRepository->save($channel);
            $this->logger->info('Channel founder transferred to successor on nick drop', [
                'channelId' => $channel->getId(),
                'channelName' => $channel->getName(),
                'newFounderNickId' => $successorNickId,
            ]);

            return;
        }

        // No successor → DROP channel
        $this->eventDispatcher->dispatch(new ChannelDropEvent(
            $channel->getId(),
            $channel->getName(),
            $channel->getNameLower(),
            'founder_dropped',
        ));
        $this->channelRepository->delete($channel);
        $this->logger->notice('Channel dropped due to founder nick drop with no successor', [
            'channelId' => $channel->getId(),
            'channelName' => $channel->getName(),
        ]);
    }
}
