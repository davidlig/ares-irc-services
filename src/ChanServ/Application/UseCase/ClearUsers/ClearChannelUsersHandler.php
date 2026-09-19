<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ClearUsers;

use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

use function count;
use function strtolower;

final readonly class ClearChannelUsersHandler implements ClearChannelUsersHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChanNetworkActions $network,
    ) {}

    public function handle(ClearChannelUsers $command): ClearChannelUsersResult
    {
        if (null === $this->channels->findByChannelName(strtolower($command->channelName))) {
            return new ClearChannelUsersResult(ClearChannelUsersOutcome::ChannelNotRegistered);
        }
        if (!$this->network->isChannelOnNetwork($command->channelName)) {
            return new ClearChannelUsersResult(ClearChannelUsersOutcome::ChannelNotOnNetwork);
        }

        $members = $this->network->getChannelMemberUids($command->channelName);
        if ([] === $members) {
            return new ClearChannelUsersResult(ClearChannelUsersOutcome::AlreadyEmpty);
        }
        foreach ($members as $uid) {
            $this->network->kickFromChannel($command->channelName, $uid, $command->reason);
        }

        return new ClearChannelUsersResult(ClearChannelUsersOutcome::Cleared, count($members));
    }
}
