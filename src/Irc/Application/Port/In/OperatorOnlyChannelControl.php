<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

interface OperatorOnlyChannelControl
{
    public function activate(string $channelName): void;

    public function deactivate(string $channelName): void;

    public function ensureMode(string $channelName): void;
}
