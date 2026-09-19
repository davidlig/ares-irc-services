<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\DeliverEntryMessage;

use App\ChanServ\Application\Port\Out\ChannelEntryMessageDelivery;
use App\ChanServ\Application\Port\Out\ChanServNetworkIdentity;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

final readonly class DeliverChannelEntryMessageHandler implements DeliverChannelEntryMessageHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelEntryMessageDelivery $delivery,
        private ChanServNetworkIdentity $identity,
    ) {}

    public function handle(DeliverChannelEntryMessage $command): void
    {
        if ($this->identity->isChanServUid($command->targetUid)) {
            return;
        }

        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel || $channel->isBlocked()) {
            return;
        }

        $entryMessage = $channel->getEntrymsg();
        if ('' === $entryMessage) {
            return;
        }

        $this->delivery->deliver($command->channelName, $command->targetUid, $entryMessage);
    }
}
