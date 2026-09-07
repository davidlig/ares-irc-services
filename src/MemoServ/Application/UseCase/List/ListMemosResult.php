<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\List;

use App\MemoServ\Application\Model\MemoListItem;

final readonly class ListMemosResult
{
    /**
     * @param list<MemoListItem> $items
     */
    private function __construct(
        public ListMemosOutcome $outcome,
        public string $targetLabel = '',
        public array $items = [],
        public ?string $channelName = null,
    ) {}

    /**
     * @param list<MemoListItem> $items
     */
    public static function success(string $targetLabel, array $items): self
    {
        return new self(
            outcome: ListMemosOutcome::Success,
            targetLabel: $targetLabel,
            items: $items,
        );
    }

    public static function empty(string $targetLabel): self
    {
        return new self(
            outcome: ListMemosOutcome::Empty,
            targetLabel: $targetLabel,
        );
    }

    public static function channelNotRegistered(string $channelName): self
    {
        return new self(
            outcome: ListMemosOutcome::ChannelNotRegistered,
            channelName: $channelName,
        );
    }
}
