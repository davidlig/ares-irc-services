<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Exception;

use DomainException;

use function sprintf;

final class ChannelAlreadyRegisteredException extends DomainException
{
    public static function forChannel(string $channelName): self
    {
        return new self(sprintf('Channel "%s" is already registered.', $channelName));
    }
}
