<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

use App\ChanServ\Domain\ValueObject\ChannelSetting;
use App\ChanServ\Domain\ValueObject\ModeCapability;

final readonly class ChannelMlockNetworkState
{
    /**
     * @param list<ChannelSetting> $settings
     * @param list<ModeCapability> $modeCapabilities
     */
    public function __construct(
        public string $name,
        public array $settings,
        public array $modeCapabilities,
    ) {}
}
