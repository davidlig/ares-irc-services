<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\Application\Shared\ServiceUidRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\PublishedEvent\UserJoinedChannelEvent;
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
        private ServiceUidRegistry $uidRegistry,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            UserJoinedChannelEvent::class => ['onUserJoinedChannel', 0],
        ];
    }

    public function onUserJoinedChannel(UserJoinedChannelEvent $event): void
    {
        $uid = $event->uid;
        if ($this->uidRegistry->getUid('chanserv') === $uid) {
            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($event->channelName));
        if (null === $channel) {
            return;
        }

        $this->sendEntryMsg($channel, $uid, $event->channelName);
    }

    private function sendEntryMsg(RegisteredChannel $channel, string $uid, string $channelName): void
    {
        if ($channel->isBlocked()) {
            return;
        }

        $entrymsg = $channel->getEntrymsg();
        if ('' === $entrymsg) {
            return;
        }

        $message = sprintf("[\x0303%s\x03] %s", $channelName, $entrymsg);
        $this->notifier->sendNotice($uid, $message);
    }
}
