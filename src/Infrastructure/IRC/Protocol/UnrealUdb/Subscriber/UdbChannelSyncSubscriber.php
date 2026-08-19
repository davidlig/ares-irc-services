<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Subscriber;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Domain\ChanServ\Event\ChannelDropEvent;
use App\Domain\ChanServ\Event\ChannelRegisteredEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\Protocol\UnrealUdb\Event\UdbSyncRequestedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

final readonly class UdbChannelSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private ActiveConnectionHolderInterface $connectionHolder,
        private RegisteredChannelRepositoryInterface $channelRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ChannelRegisteredEvent::class => 'onChannelRegistered',
            ChannelDropEvent::class => 'onChannelDrop',
            UdbSyncRequestedEvent::class => 'onSyncRequested',
        ];
    }

    public function onChannelDrop(ChannelDropEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $this->connectionHolder->writeLine(sprintf('DB * DEL C::%s', $event->channelName));
    }

    public function onChannelRegistered(ChannelRegisteredEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        $channel = $this->channelRepository->findByChannelName($event->channelNameLower);
        if (null === $channel) {
            return;
        }

        $founder = $this->nickRepository->findById($channel->getFounderNickId());
        if (null === $founder) {
            return;
        }

        $this->connectionHolder->writeLine(sprintf('DB * INS C::%s::founder %s', $event->channelName, $founder->getNickname()));
    }

    public function onSyncRequested(UdbSyncRequestedEvent $event): void
    {
        if (!$this->connectionHolder->isConnected()) {
            return;
        }

        if ('C' === $event->block) {
            $this->connectionHolder->writeLine('DB * DRP C');

            $channels = $this->channelRepository->listAll();
            foreach ($channels as $channel) {
                $founder = $this->nickRepository->findById($channel->getFounderNickId());
                if (null !== $founder) {
                    $this->connectionHolder->writeLine(sprintf('DB * INS C::%s::founder %s', $channel->getName(), $founder->getNickname()));
                }
            }

            $this->connectionHolder->writeLine('DB * FDR C');
        }
    }
}
