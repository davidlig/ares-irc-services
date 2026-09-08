<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Shared\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Shared\Application\Port\ChannelModeSupportInterface;

final readonly class ActiveChannelModeSupportProvider implements ActiveChannelModeSupportProviderInterface
{
    public function __construct(
        private ActiveConnectionHolder $connectionHolder,
        private NullChannelModeSupport $nullSupport,
    ) {}

    public function getSupport(): ChannelModeSupportInterface
    {
        $module = $this->connectionHolder->getProtocolModule();

        return null !== $module ? $module->getChannelModeSupport() : $this->nullSupport;
    }
}
