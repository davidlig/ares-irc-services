<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\ChannelRankActions;
use App\ChanServ\Domain\ValueObject\RankChangeAction;
use App\Shared\Application\Port\ChannelServiceActionsPort;

final readonly class IrcChannelRankActions implements ChannelRankActions
{
    private const int MAX_CHANGES_PER_COMMAND = 6;

    public function __construct(
        private ChannelServiceActionsPort $networkActions,
        private ChannelRankModeMapper $rankMapper,
    ) {}

    public function apply(string $channelName, array $changes): void
    {
        foreach (array_chunk($changes, self::MAX_CHANGES_PER_COMMAND) as $chunk) {
            $modeString = '';
            $currentSign = '';
            $params = [];

            foreach ($chunk as $memberChange) {
                $sign = RankChangeAction::Grant === $memberChange->change->action ? '+' : '-';
                if ($sign !== $currentSign) {
                    $modeString .= $sign;
                    $currentSign = $sign;
                }
                $modeString .= $this->rankMapper->toLetter($memberChange->change->rank);
                $params[] = $memberChange->uid;
            }

            $this->networkActions->setChannelModes($channelName, $modeString, $params);
        }
    }
}
