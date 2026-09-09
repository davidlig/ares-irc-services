<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Takeover;

use App\Irc\Adapter\Protocol\UnrealUdb\Model\UdbBlock;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbUnsignedDecimal;

final readonly class UdbWireTakeoverOutcome
{
    private function __construct(
        public UdbWireTakeoverOutcomeKind $kind,
        public ?UdbBlock $block = null,
        public ?UdbUnsignedDecimal $roundId = null,
        public ?string $txid = null,
        public ?string $digest = null,
        public ?string $subcommand = null,
        public ?int $errorCode = null,
    ) {}

    public static function ignored(): self
    {
        return new self(UdbWireTakeoverOutcomeKind::Ignored);
    }

    public static function request(UdbBlock $block, UdbUnsignedDecimal $roundId): self
    {
        return new self(UdbWireTakeoverOutcomeKind::Request, $block, $roundId);
    }

    public static function acknowledge(UdbBlock $block, UdbUnsignedDecimal $roundId, string $txid, string $digest): self
    {
        return new self(UdbWireTakeoverOutcomeKind::Acknowledge, $block, $roundId, $txid, $digest);
    }

    public static function error(UdbBlock $block, UdbUnsignedDecimal $roundId, string $subcommand, int $errorCode): self
    {
        return new self(UdbWireTakeoverOutcomeKind::Error, $block, $roundId, subcommand: $subcommand, errorCode: $errorCode);
    }
}
