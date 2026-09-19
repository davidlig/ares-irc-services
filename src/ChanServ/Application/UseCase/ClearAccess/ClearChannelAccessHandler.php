<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearAccess;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

use function strtolower;

final readonly class ClearChannelAccessHandler implements ClearChannelAccessHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $access,
    ) {}

    public function handle(ClearChannelAccess $command): ClearChannelAccessResult
    {
        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel) {
            return new ClearChannelAccessResult(ClearChannelAccessOutcome::ChannelNotRegistered);
        }

        $count = $this->access->countByChannel($channel->getId());
        if (0 === $count) {
            return new ClearChannelAccessResult(ClearChannelAccessOutcome::AlreadyEmpty);
        }

        $this->access->deleteByChannelId($channel->getId());

        return new ClearChannelAccessResult(ClearChannelAccessOutcome::Cleared, $count);
    }
}
