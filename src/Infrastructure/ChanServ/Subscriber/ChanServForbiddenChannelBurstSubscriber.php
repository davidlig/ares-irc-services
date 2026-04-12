<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Subscriber;

use App\Application\ChanServ\Service\ChannelForbiddenService;
use App\Application\Port\ChannelLookupPort;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;
use function sprintf;

final readonly class ChanServForbiddenChannelBurstSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelLookupPort $channelLookup,
        private ChannelForbiddenService $forbiddenService,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSyncCompleteEvent::class => ['onNetworkSyncComplete', 10],
        ];
    }

    public function onNetworkSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $forbiddenChannels = $this->channelRepository->findForbiddenChannels();

        if ([] === $forbiddenChannels) {
            return;
        }

        $this->logger->info(sprintf(
            'ChanServForbiddenChannelBurst: enforcing %d forbidden channel(s)',
            count($forbiddenChannels),
        ));

        foreach ($forbiddenChannels as $channel) {
            $view = $this->channelLookup->findByChannelName($channel->getName());

            if (null === $view) {
                $this->logger->debug(sprintf(
                    'ChanServForbiddenChannelBurst: channel %s not on network, skipping',
                    $channel->getName(),
                ));

                continue;
            }

            $this->forbiddenService->enforceForbiddenChannel($channel->getName());
        }
    }
}
