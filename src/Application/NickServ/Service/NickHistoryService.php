<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Domain\NickServ\Entity\NickHistory;
use App\Domain\NickServ\Repository\NickHistoryRepositoryInterface;
use DateTimeImmutable;

/**
 * Centralized service for recording nickname history entries.
 */
final readonly class NickHistoryService
{
    public function __construct(
        private NickHistoryRepositoryInterface $historyRepository,
    ) {
    }

    /**
     * Record a history entry for a nickname.
     *
     * @param int      $nickId            The nickname ID where action was performed
     * @param string   $action            Action type (SUSPEND, SET_PASSWORD, etc.)
     * @param string   $performedBy       Nickname of the operator who performed the action
     * @param int|null $performedByNickId Nick ID of operator (null if not registered)
     * @param string   $performedByIp     IP address of the operator
     * @param string   $performedByHost   Full host (ident@hostname) of the operator
     * @param string   $message           Human-readable description
     * @param array    $extraData         Additional context (old_value, new_value, duration, etc.)
     */
    public function recordAction(
        int $nickId,
        string $action,
        string $performedBy,
        ?int $performedByNickId,
        string $performedByIp,
        string $performedByHost,
        string $message,
        array $extraData = [],
        ?DateTimeImmutable $performedAt = null,
    ): NickHistory {
        $extra = array_merge([
            'ip' => $performedByIp,
            'host' => $performedByHost,
        ], $extraData);

        $history = NickHistory::record(
            nickId: $nickId,
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
