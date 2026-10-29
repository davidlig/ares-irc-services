<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\Unban;

use App\ChanServ\Application\Port\Out\ChannelBanActions;
use App\ChanServ\Application\Port\Out\ChannelBanNetworkQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;

use function count;
use function strtolower;

final readonly class UnbanChannelHandler implements UnbanChannelHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChanServAccessHelper $access,
        private ChannelBanNetworkQuery $network,
        private ChannelBanActions $actions,
    ) {}

    public function handle(UnbanChannel $command): UnbanChannelResult
    {
        $channel = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $channel) {
            throw ChannelNotRegisteredException::forChannel($command->channelName);
        }
        if (!$command->founderEquivalent) {
            $this->access->requireLevel($channel, $command->accountId, ChannelLevel::KEY_UNBAN, $command->channelName, 'UNBAN');
        }
        $snapshot = $this->network->findMatchingBans($command->channelName, $command->requesterUid);
        if (null === $snapshot) {
            return new UnbanChannelResult(UnbanChannelOutcome::Unavailable);
        }
        if (!$snapshot->channelExists) {
            return new UnbanChannelResult(UnbanChannelOutcome::ChannelNotOnNetwork);
        }
        if ([] === $snapshot->masks) {
            return new UnbanChannelResult(UnbanChannelOutcome::NoMatches);
        }
        $this->actions->removeBans($command->channelName, $snapshot->masks, $snapshot->channelTimestamp);

        return new UnbanChannelResult(UnbanChannelOutcome::Removed, count($snapshot->masks));
    }
}
