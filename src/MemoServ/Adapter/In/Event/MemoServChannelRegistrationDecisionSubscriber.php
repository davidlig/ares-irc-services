<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Event;

use App\ChanServ\Application\PublishedEvent\ChannelRegistrationDecisionEvent;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecision;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecisionHandlerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/** Bridges ChanServ's published decision event into MemoServ's local use case. */
final readonly class MemoServChannelRegistrationDecisionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private NotifyChannelRegistrationDecisionHandlerInterface $handler,
        private LoggerInterface $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [ChannelRegistrationDecisionEvent::class => ['onDecision', 0]];
    }

    public function onDecision(ChannelRegistrationDecisionEvent $event): void
    {
        try {
            $this->handler->handle(new NotifyChannelRegistrationDecision(
                $event->channelId,
                $event->channelName,
                $event->requesterAccountId,
                $event->operatorAccountId,
                $event->operatorNickname,
                $event->accepted,
                $event->rejectionReason,
                $event->restrictedUntil,
                $event->occurredAt,
            ));
        } catch (Throwable) {
            try {
                $this->logger->warning('Could not store a channel registration decision memo.', [
                    'channel_id' => $event->channelId,
                    'requester_account_id' => $event->requesterAccountId,
                    'accepted' => $event->accepted,
                ]);
            } catch (Throwable) {
                // The already persisted ChanServ decision must remain successful even if logging is unavailable.
            }
        }
    }
}
