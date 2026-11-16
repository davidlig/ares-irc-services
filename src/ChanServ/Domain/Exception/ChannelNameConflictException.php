<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\Exception;

use DomainException;

use function sprintf;

/**
 * The requested channel shares its stored lookup key with a registration of another channel on
 * this network (the names differ under the negotiated casemapping), so it cannot act on that row.
 */
final class ChannelNameConflictException extends DomainException
{
    public function __construct(
        string $message,
        private readonly string $channelName,
        private readonly string $registeredName,
    ) {
        parent::__construct($message);
    }

    public static function forChannel(string $channelName, string $registeredName): self
    {
        return new self(
            sprintf('Channel "%s" shares its registration key with the different channel "%s".', $channelName, $registeredName),
            $channelName,
            $registeredName,
        );
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }

    public function getRegisteredName(): string
    {
        return $this->registeredName;
    }
}
