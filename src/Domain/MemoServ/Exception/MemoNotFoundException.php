<?php

declare(strict_types=1);

namespace App\Domain\MemoServ\Exception;

use RuntimeException;

use function sprintf;

class MemoNotFoundException extends RuntimeException
{
    public static function forIndex(int $index): self
    {
        return new self(sprintf('Memo #%d not found.', $index));
    }
}
