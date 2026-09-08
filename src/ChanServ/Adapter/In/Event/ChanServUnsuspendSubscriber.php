<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Event;

use App\Application\Port\ServiceDebugNotifierInterface;
use App\ChanServ\Application\Port\In\UnsuspendedChannelRestoration;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ChanServUnsuspendSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UnsuspendedChannelRestoration $restoration,
        private ServiceDebugNotifierInterface $debugNotifier,
        private TranslatorInterface $translator,
        private string $defaultLanguage = 'en',
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [ChannelUnsuspendedEvent::class => ['onChannelUnsuspended', 0]];
    }

    public function onChannelUnsuspended(ChannelUnsuspendedEvent $event): void
    {
        $channelName = $this->restoration->restore($event->channelNameLower);
        if (null === $channelName) {
            return;
        }

        $this->debugNotifier->log(
            operator: $event->performedBy,
            command: 'UNSUSPEND',
            target: $channelName,
            reason: $this->translator->trans('unsuspend.reason_expired', [], 'chanserv', $this->defaultLanguage),
        );
    }
}
