<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ApplicationPort\ServiceUidRegistry;
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
        $uid = $event->uid->value;
        if ($this->uidRegistry->getUid('chanserv') === $uid) {
            return;
        }

        $channel = $this->channelRepository->findByChannelName(strtolower($event->channel->value));
        if (null === $channel) {
            return;
        }

        $this->sendEntryMsg($channel, $uid, $event->channel->value);
    }

    private function sendEntryMsg(object $channel, string $uid, string $channelName): void
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
