<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

use DateTimeImmutable;
use InvalidArgumentException;

use function strlen;

final readonly class AkickRule
{
    public const int MAX_REASON_LENGTH = 255;

    public ?string $reason;

    public function __construct(
        public AkickMask $mask,
        ?string $reason = null,
        public ?DateTimeImmutable $expiresAt = null,
    ) {
        if (null !== $reason && self::MAX_REASON_LENGTH < strlen($reason)) {
            throw new InvalidArgumentException('AKICK reason cannot exceed 255 characters.');
        }

        $this->reason = '' === $reason ? null : $reason;
    }

    public function isExpiredAt(DateTimeImmutable $now): bool
    {
        return null !== $this->expiresAt && $this->expiresAt < $now;
    }

    public function enforcementReason(): string
    {
        return $this->reason ?? 'AKICK: ' . $this->mask->value;
    }
}
