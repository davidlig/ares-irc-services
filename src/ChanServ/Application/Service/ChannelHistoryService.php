<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Service;

use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelHistory;
use DateTimeImmutable;

use function array_merge;

final readonly class ChannelHistoryService
{
    public function __construct(
        private ChannelHistoryRepositoryInterface $historyRepository,
    ) {}

    /**
     * @param array<string, mixed> $extraData
     */
    public function recordAction(
        int $channelId,
        string $action,
        string $performedBy,
        ?int $performedByNickId,
        string $performedByIp,
        string $performedByHost,
        string $message,
        array $extraData = [],
        ?DateTimeImmutable $performedAt = null,
    ): ChannelHistory {
        $extra = array_merge([
            'ip' => $performedByIp,
            'host' => $performedByHost,
        ], $extraData);

        $history = ChannelHistory::record(
            channelId: $channelId,
            action: $action,
            performedBy: $performedBy,
            performedByNickId: $performedByNickId,
            message: $message,
            extraData: $extra,
            performedAt: $performedAt,
        );

        $this->historyRepository->save($history);

        return $history;
    }
}
