<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\ChannelBanActions;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;

use function array_chunk;
use function count;
use function str_repeat;

final readonly class IrcChannelBanActions implements ChannelBanActions
{
    private const int MAX_BANS_PER_COMMAND = 6;

    public function __construct(private ChannelServiceActionsPort $actions) {}

    public function removeBans(string $channelName, array $masks, ?int $channelTimestamp): void
    {
        if ([] === $masks) {
            return;
        }

        foreach (array_chunk($masks, self::MAX_BANS_PER_COMMAND) as $chunk) {
            $this->actions->setChannelModes(
                $channelName,
                '-' . str_repeat('b', count($chunk)),
                $chunk,
                $channelTimestamp,
            );
        }
    }
}
