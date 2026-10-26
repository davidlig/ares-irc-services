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
        DateTimeImmutable $performedAt,
        string $message,
        array $extraData = [],
    ): ChannelHistory {
        $extra = array_merge([
            'ip' => $performedByIp,
            'host' => $performedByHost,
        ], $extraData);

        return $this->recordWithExplicitMetadata(
            channelId: $channelId,
            action: $action,
            performedBy: $performedBy,
            performedByNickId: $performedByNickId,
            performedAt: $performedAt,
            message: $message,
            extraData: $extra,
        );
    }

    /**
     * Records only explicitly approved metadata, without the actor IP/host enrichment
     * retained for subscriber-driven history records.
     *
     * @param array<string, mixed> $extraData
     */
    public function recordWithExplicitMetadata(
        int $channelId,
        string $action,
        string $performedBy,
        ?int $performedByNickId,
        DateTimeImmutable $performedAt,
        string $message,
        array $extraData = [],
    ): ChannelHistory {
        $history = ChannelHistory::record(
            channelId: $channelId,
            action: $action,
            performedBy: $performedBy,
            performedByNickId: $performedByNickId,
            performedAt: $performedAt,
            message: $message,
            extraData: $extraData,
        );

        $this->historyRepository->save($history);

        return $history;
    }
}
