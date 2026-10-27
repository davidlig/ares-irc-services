<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Application\Port\Out\ServiceMemoSender;
use RuntimeException;

final readonly class ConfiguredChanServMemoSender implements ServiceMemoSender
{
    public function __construct(private ServiceNicknameRegistry $serviceNicknameRegistry) {}

    public function getSenderName(): string
    {
        return $this->serviceNicknameRegistry->getNickname('chanserv')
            ?? throw new RuntimeException('Configured ChanServ nickname is unavailable.');
    }
}
