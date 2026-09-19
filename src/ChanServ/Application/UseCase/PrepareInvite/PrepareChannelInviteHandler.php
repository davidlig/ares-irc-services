<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\PrepareInvite;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;

use function strtolower;

final readonly class PrepareChannelInviteHandler implements PrepareChannelInviteHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChanServAccessHelper $access,
    ) {}

    public function handle(PrepareChannelInvite $query): PrepareChannelInviteResult
    {
        $channel = $this->channels->findByChannelName(strtolower($query->channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($query->channelName);
        }
        if (!$query->founderEquivalent) {
            $this->access->requireLevel($channel, $query->accountId, ChannelLevel::KEY_INVITE, $query->channelName, 'INVITE');
        }

        return new PrepareChannelInviteResult($channel->getCreatedAt()->getTimestamp());
    }
}
