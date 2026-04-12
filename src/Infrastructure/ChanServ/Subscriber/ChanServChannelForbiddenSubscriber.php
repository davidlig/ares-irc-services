<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Domain\ChanServ\Event\ChannelForbiddenEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

final readonly class ChanServChannelForbiddenSubscriber implements EventSubscriberInterface
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
            ChannelForbiddenEvent::class => ['onChannelForbidden', 0],
        ];
    }

    public function onChannelForbidden(ChannelForbiddenEvent $event): void
    {
        $channelName = $event->channelName;
        $view = $this->channelLookup->findByChannelName($channelName);

        if (null === $view) {
            $this->logger->debug(sprintf(
                'ChannelForbidden: channel %s not found on network, skipping enforcement',
                $channelName,
            ));

            return;
        }

        $this->channelServiceActions->joinChannelAsService($channelName, $view->timestamp);

        foreach ($view->members as $member) {
            $this->channelServiceActions->kickFromChannel(
                $channelName,
                $member['uid'],
                'Forbidden channel',
            );
        }

        $this->channelServiceActions->setChannelModes($channelName, '+ntims', []);

        $this->logger->info(sprintf(
            'ChannelForbidden: enforced forbidden channel %s (bot joined, users kicked, +ntims set)',
            $channelName,
        ));
    }
}
