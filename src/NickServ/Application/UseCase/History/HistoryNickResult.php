<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\History;

final readonly class HistoryNickResult
{
    /**
     * @param NickHistoryEntryView[] $entries
     */
    private function __construct(
        public HistoryNickOutcome $outcome,
        public ?string $targetNick = null,
        public ?string $message = null,
        public ?int $entryId = null,
        public ?string $rawId = null,
        public int $deletedCount = 0,
        public array $entries = [],
        public int $start = 0,
        public int $end = 0,
        public int $total = 0,
        public int $page = 1,
        public int $totalPages = 1,
        public bool $showAll = false,
    ) {}

    public static function notRegistered(string $targetNick): self
    {
        return new self(HistoryNickOutcome::NotRegistered, targetNick: $targetNick);
    }

    public static function addSuccess(string $targetNick, string $message): self
    {
        return new self(HistoryNickOutcome::AddSuccess, targetNick: $targetNick, message: $message);
    }

    public static function delInvalidId(string $rawId): self
    {
        return new self(HistoryNickOutcome::DelInvalidId, rawId: $rawId);
    }

    public static function delNotFound(int $entryId): self
    {
        return new self(HistoryNickOutcome::DelNotFound, entryId: $entryId);
    }

    public static function delSuccess(string $targetNick, int $entryId): self
    {
        return new self(HistoryNickOutcome::DelSuccess, targetNick: $targetNick, entryId: $entryId);
    }

    public static function clearSuccess(string $targetNick, int $deletedCount): self
    {
        return new self(HistoryNickOutcome::ClearSuccess, targetNick: $targetNick, deletedCount: $deletedCount);
    }

    public static function viewNoEntries(string $targetNick): self
    {
        return new self(HistoryNickOutcome::ViewNoEntries, targetNick: $targetNick);
    }

    /**
     * @param NickHistoryEntryView[] $entries
     */
    public static function viewSuccess(
        string $targetNick,
        array $entries,
        int $start,
        int $end,
        int $total,
        int $page,
        int $totalPages,
        bool $showAll,
    ): self {
        return new self(
            HistoryNickOutcome::ViewSuccess,
            targetNick: $targetNick,
            entries: $entries,
            start: $start,
            end: $end,
            total: $total,
            page: $page,
            totalPages: $totalPages,
            showAll: $showAll,
        );
    }
}
