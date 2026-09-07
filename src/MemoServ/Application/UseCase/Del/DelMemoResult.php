<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Del;

final readonly class DelMemoResult
{
    private function __construct(
        public DelMemoOutcome $outcome,
        public ?int $index = null,
        public ?string $channelName = null,
    ) {}

    public static function deleted(int $index): self
    {
        return new self(
            outcome: DelMemoOutcome::Deleted,
            index: $index,
        );
    }

    public static function channelNotRegistered(string $channelName): self
    {
        return new self(
            outcome: DelMemoOutcome::ChannelNotRegistered,
            channelName: $channelName,
        );
    }

    public static function accessDenied(string $channelName): self
    {
        return new self(
            outcome: DelMemoOutcome::AccessDenied,
            channelName: $channelName,
        );
    }

    public static function notFound(int $index): self
    {
        return new self(
            outcome: DelMemoOutcome::NotFound,
            index: $index,
        );
    }
}
