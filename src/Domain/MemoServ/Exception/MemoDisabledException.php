<?php

declare(strict_types=1);

namespace App\Domain\MemoServ\Exception;

use RuntimeException;

use function sprintf;

class MemoDisabledException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $target,
    ) {
        parent::__construct($message);
    }

    public static function forTarget(string $target): self
    {
        return new self(sprintf('Message service is disabled for %s.', $target), $target);
    }
}
