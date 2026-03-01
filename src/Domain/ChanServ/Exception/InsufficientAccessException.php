<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Exception;

use DomainException;

use function sprintf;

final class InsufficientAccessException extends DomainException
{
    public static function forChannel(string $channelName): self
    {
        return new self(sprintf('Insufficient access for channel "%s".', $channelName));
    }

    public static function forOperation(string $channelName, string $operation): self
    {
        return new self(sprintf('Insufficient access to %s on channel "%s".', $operation, $channelName));
    }
}
