<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Network;

use App\ChanServ\Application\Port\Out\ChannelModeActions;
use App\ChanServ\Domain\ValueObject\ModeChangeAction;
use App\Shared\Application\Port\ChannelServiceActionsPort;

final readonly class IrcChannelModeActions implements ChannelModeActions
{
    public function __construct(private ChannelServiceActionsPort $networkActions) {}

    public function apply(string $channelName, array $changes): void
    {
        $modeString = '';
        $currentSign = '';
        $params = [];

        foreach ($changes as $change) {
            $sign = ModeChangeAction::Add === $change->action ? '+' : '-';
            if ($sign !== $currentSign) {
                $modeString .= $sign;
                $currentSign = $sign;
            }
            $modeString .= $change->mode->value;
            if (null !== $change->parameter) {
                $params[] = $change->parameter;
            }
        }

        if ('' !== $modeString) {
            $this->networkActions->setChannelModes($channelName, $modeString, $params);
        }
    }
}
