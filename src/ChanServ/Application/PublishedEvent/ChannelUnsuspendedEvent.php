<?php

declare(strict_types=1);

namespace App\ChanServ\Application\PublishedEvent;

use App\ChanServ\Application\Port\In\ChanServEvent;
use DateTimeImmutable;

/**
 * Dispatched when a channel suspension is lifted.
 *
 * A retry ({@see self::$isRetry}) is dispatched again only because a listener failed after the
 * unsuspension was committed: listeners that restore state (UDB, network) handle it again, while
 * listeners that record or announce the unsuspension (history, debug notice) skip it, since they
 * already handled the first dispatch.
 */
final readonly class ChannelUnsuspendedEvent implements ChanServEvent
{
    public function __construct(
        public int $channelId,
        public string $channelName,
        public string $channelNameLower,
        public string $performedBy,
        public ?int $performedByNickId,
        public string $performedByIp,
        public string $performedByHost,
        public DateTimeImmutable $occurredAt,
        public bool $isRetry = false,
    ) {}

    public function retry(): self
    {
        return new self(
            $this->channelId,
            $this->channelName,
            $this->channelNameLower,
            $this->performedBy,
            $this->performedByNickId,
            $this->performedByIp,
            $this->performedByHost,
            $this->occurredAt,
            true,
        );
    }
}
