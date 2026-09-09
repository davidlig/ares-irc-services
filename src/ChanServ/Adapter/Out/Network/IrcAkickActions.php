<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\AkickActions;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class IrcAkickActions implements AkickActions
{
    public function __construct(
        private ChannelServiceActionsPort $networkActions,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function banAndKick(string $channelName, string $uid, string $mask, string $reason): void
    {
        $this->networkActions->setChannelModes($channelName, '+b', [$mask]);
        $this->networkActions->kickFromChannel($channelName, $uid, $reason);

        $this->logger->info('AKICK enforced', [
            'channel' => $channelName,
            'mask' => $mask,
            'uid' => $uid,
        ]);
    }
}
