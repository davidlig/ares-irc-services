<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Takeover;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;

final readonly class UdbWireTakeoverOutcome
{
    private function __construct(
        public UdbWireTakeoverOutcomeKind $kind,
        public ?UdbBlock $block = null,
        public ?int $roundId = null,
        public ?string $txid = null,
        public ?string $digest = null,
        public ?string $subcommand = null,
        public ?int $errorCode = null,
    ) {}

    public static function ignored(): self
    {
        return new self(UdbWireTakeoverOutcomeKind::Ignored);
    }

    public static function request(UdbBlock $block, int $roundId): self
    {
        return new self(UdbWireTakeoverOutcomeKind::Request, $block, $roundId);
    }

    public static function acknowledge(UdbBlock $block, int $roundId, string $txid, string $digest): self
    {
        return new self(UdbWireTakeoverOutcomeKind::Acknowledge, $block, $roundId, $txid, $digest);
    }

    public static function error(UdbBlock $block, int $roundId, string $subcommand, int $errorCode): self
    {
        return new self(UdbWireTakeoverOutcomeKind::Error, $block, $roundId, subcommand: $subcommand, errorCode: $errorCode);
    }
}
