<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Exception;

use DomainException;

use function sprintf;

final class ChannelNotRegisteredException extends DomainException
{
    public function __construct(
        string $message,
        private readonly string $channelName,
    ) {
        parent::__construct($message);
    }

    public static function forChannel(string $channelName): self
    {
        return new self(sprintf('Channel "%s" is not registered.', $channelName), $channelName);
    }

    public function getChannelName(): string
    {
        return $this->channelName;
    }
}
