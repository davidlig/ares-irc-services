<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ShowRegistrationStatus;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

final readonly class ShowRegistrationStatusHandler
{
    public function __construct(private RegisteredChannelRepositoryInterface $channels) {}

    public function handle(string $channelName): ?RegistrationStatusView
    {
        $channel = $this->channels->findByChannelName($channelName);
        if (null === $channel) {
            return null;
        }

        return new RegistrationStatusView(
            $channel->getStatus(),
            $channel->getFounderNickId(),
            $channel->getRegistrationRejectionReason(),
            $channel->getRegistrationRejectedByNickname(),
            $channel->getRegistrationRejectedAt(),
            $channel->getRegistrationRestrictedUntil(),
        );
    }
}
