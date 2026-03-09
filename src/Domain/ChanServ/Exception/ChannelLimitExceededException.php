<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Exception;

use DomainException;

use function sprintf;

final class ChannelLimitExceededException extends DomainException
{
    public static function forNickname(string $nickname, int $maxChannels): self
    {
        return new self(sprintf(
            'Nickname "%s" has reached the maximum of %d registered channels.',
            $nickname,
            $maxChannels
        ));
    }
}
