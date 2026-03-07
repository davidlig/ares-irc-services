<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\UserJoinedChannelEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

/**
 * Sends the channel ENTRYMSG (welcome message) as a NOTICE to each user when they join
 * a registered channel that has an entry message configured.
 * Prefix format: [<green>#channel</green>] message (IRC color code 03 = green).
 */
final readonly class ChanServEntryMsgSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanServNotifierInterface $notifier,
        private string $chanservUid,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
        ];
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $uid = $event->uid->value;
        if ($uid === $this->chanservUid) {
            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($event->channel->value));
        if (null === $channel) {
            return;
        }

        $entrymsg = $channel->getEntrymsg();
        if ('' === $entrymsg) {
            return;
        }

        $message = sprintf("[\x0303%s\x03] %s", $event->channel->value, $entrymsg);
        $this->notifier->sendNotice($uid, $message);
    }
}
