<?php

declare(strict_types=1);

namespace App\CRegServ\Domain\Exception;

use DomainException;
use Throwable;

use function sprintf;

/**
 * Raised when two registration requests race for the same channel: both passed the
 * read-then-write duplicate guard and the loser hit the unique index on channel_lower.
 *
 * The repository translates Doctrine's driver-level violation into this so the use case can
 * report a plain duplicate instead of leaking persistence detail into the Application layer.
 */
final class ChannelRegistrationConflictException extends DomainException
{
    private function __construct(
        string $message,
        private readonly string $channelLower,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function forChannel(string $channelLower, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('A registration request for "%s" already exists.', $channelLower),
            $channelLower,
            $previous,
        );
    }

    public function getChannelLower(): string
    {
        return $this->channelLower;
    }
}
