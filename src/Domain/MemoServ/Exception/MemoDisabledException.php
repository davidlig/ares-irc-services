<?php

declare(strict_types=1);

namespace App\Domain\MemoServ\Exception;

use RuntimeException;

use function sprintf;

class MemoDisabledException extends RuntimeException
{
    public static function forTarget(string $target): self
    {
        return new self(sprintf('Memo service is disabled for %s.', $target));
    }
}
