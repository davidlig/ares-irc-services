<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Service;

use App\ChanServ\Application\Port\Out\NickDropCleanupActivitySink;
use Psr\Log\LoggerInterface;

final readonly class PsrNickDropCleanupActivitySink implements NickDropCleanupActivitySink
{
    public function __construct(private LoggerInterface $logger) {}

    public function founderTransferred(int $channelId, string $channelName, int $newFounderNickId): void
    {
        $this->logger->info('Channel founder transferred to successor on nick drop', [
            'channelId' => $channelId,
            'channelName' => $channelName,
            'newFounderNickId' => $newFounderNickId,
        ]);
    }

    public function channelDropped(int $channelId, string $channelName): void
    {
        $this->logger->notice('Channel dropped due to founder nick drop with no successor', [
            'channelId' => $channelId,
            'channelName' => $channelName,
        ]);
    }
}
