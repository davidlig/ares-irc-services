<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Read;

use DateTimeImmutable;

final readonly class ReadMemoResult
{
    private function __construct(
        public ReadMemoOutcome $outcome,
        public ?int $index = null,
        public ?string $from = null,
        public ?string $message = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?string $channelName = null,
    ) {}

    public static function success(int $index, string $from, string $message, DateTimeImmutable $createdAt): self
    {
        return new self(
            outcome: ReadMemoOutcome::Success,
            index: $index,
            from: $from,
            message: $message,
            createdAt: $createdAt,
        );
    }

    public static function channelNotRegistered(string $channelName): self
    {
        return new self(
            outcome: ReadMemoOutcome::ChannelNotRegistered,
            channelName: $channelName,
        );
    }

    public static function notFound(int $index): self
    {
        return new self(
            outcome: ReadMemoOutcome::NotFound,
            index: $index,
        );
    }
}
