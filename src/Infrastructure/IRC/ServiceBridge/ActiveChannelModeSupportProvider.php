<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\ActiveChannelModeSupportProviderInterface;
use App\Application\Port\ChannelModeSupportInterface;
use App\Irc\Adapter\Out\Connection\ActiveConnectionHolder;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;

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
