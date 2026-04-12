<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Event\ChannelUnforbiddenEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

final readonly class ChanServChannelUnforbiddenSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ChannelServiceActionsPort $channelServiceActions,
        private ChannelLookupPort $channelLookup,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelUnforbiddenEvent::class => ['onChannelUnforbidden', 0],
        ];
    }

    public function onChannelUnforbidden(ChannelUnforbiddenEvent $event): void
    {
        $channelName = $event->channelName;
        $view = $this->channelLookup->findByChannelName($channelName);

        if (null === $view) {
            $this->logger->debug(sprintf(
                'ChannelUnforbidden: channel %s not found on network, no action needed',
                $channelName,
            ));

            return;
        }

        $this->channelServiceActions->partChannelAsService($channelName);

        $this->logger->info(sprintf(
            'ChannelUnforbidden: bot left forbidden channel %s',
            $channelName,
        ));
    }
}
