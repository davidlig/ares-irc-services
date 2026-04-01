<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

use function fnmatch;
use function preg_match;
use function sprintf;
use function strlen;
use function strtolower;

class Gline
{
    public const int MAX_MASK_LENGTH = 255;

    public const int MAX_REASON_LENGTH = 255;

    public const int MAX_ENTRIES = 1000;

    public const int MIN_ALNUM_CHARS = 4;

    private int $id;

    private string $mask;

    private ?int $creatorNickId = null;

    private ?string $reason = null;

    private DateTimeImmutable $createdAt;

    private ?DateTimeImmutable $expiresAt = null;

    private function __construct(
        string $mask,
        ?int $creatorNickId,
        ?string $reason,
        ?DateTimeImmutable $expiresAt,
    ) {
        $this->setMask($mask);
        $this->creatorNickId = $creatorNickId;
        $this->setReason($reason);
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public static function create(
        string $mask,
        ?int $creatorNickId = null,
        ?string $reason = null,
        ?DateTimeImmutable $expiresAt = null,
    ): self {
        return new self($mask, $creatorNickId, $reason, $expiresAt);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getMask(): string
    {
        return $this->mask;
    }

    public function getCreatorNickId(): ?int
    {
        return $this->creatorNickId;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function isExpired(): bool
    {
        return null !== $this->expiresAt && $this->expiresAt < new DateTimeImmutable();
    }

    public function isPermanent(): bool
    {
        return null === $this->expiresAt;
    }

    public function matches(string $userMask): bool
    {
        $pattern = strtolower($this->mask);
        $subject = strtolower($userMask);

        return fnmatch($pattern, $subject);
    }

    public function updateReason(?string $reason): void
    {
        $this->setReason($reason);
    }

    public function updateExpiry(?DateTimeImmutable $expiresAt): void
    {
        $this->expiresAt = $expiresAt;
    }

    private function setMask(string $mask): void
    {
        if ('' === $mask || strlen($mask) > self::MAX_MASK_LENGTH) {
            throw new InvalidArgumentException(sprintf('Mask must be between 1 and %d characters.', self::MAX_MASK_LENGTH));
        }

        if (!self::isUserHostMask($mask)) {
            throw new InvalidArgumentException('Invalid mask format. Must be in format user@host (e.g., *@192.168.*).');
        }

        $this->mask = $mask;
    }

    private function setReason(?string $reason): void
    {
        if (null !== $reason && strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf('Reason cannot exceed %d characters.', self::MAX_REASON_LENGTH));
        }

        $this->reason = '' !== $reason ? $reason : null;
    }

    public static function isValidMask(string $mask): bool
    {
        if ('' === $mask) {
            return false;
        }

        // GLINE masks can be:
        // 1. user@host format (must have @ and no !)
        // 2. nickname (no @, no !) - will be resolved to user@host later
        return !str_contains($mask, '!');
    }

    public static function isUserHostMask(string $mask): bool
    {
        // Final user@host format (for persistence)
        return substr_count($mask, '@') >= 1 && !str_contains($mask, '!');
    }

    public static function isNicknameMask(string $mask): bool
    {
        // Nickname mask: no @, no !, not empty
        return !str_contains($mask, '@') && !str_contains($mask, '!') && '' !== $mask;
    }

    public static function isSafeMask(string $mask): bool
    {
        // GLINE masks must not contain ! (that's for AKICK format)
        if (str_contains($mask, '!')) {
            return false;
        }

        $atPos = strpos($mask, '@');
        if (false === $atPos) {
            return false;
        }

        $userPart = substr($mask, 0, $atPos);
        $hostPart = substr($mask, $atPos + 1);

        // If user part has alphanumeric chars, mask is specific enough (e.g., ares-859015@*)
        foreach (str_split($userPart) as $char) {
            if (ctype_alnum($char)) {
                return true;
            }
        }

        // User is only wildcards, check host has enough alphanumeric chars
        $hostAlnumCount = 0;
        foreach (str_split($hostPart) as $char) {
            if (ctype_alnum($char)) {
                ++$hostAlnumCount;
            }
        }

        return $hostAlnumCount >= self::MIN_ALNUM_CHARS;
    }

    public static function isGlobalMask(string $mask): bool
    {
        $lower = strtolower(trim($mask));

        if ('*' === $lower || '*!*@*' === $lower || '*@*' === $lower) {
            return true;
        }

        if (preg_match('/^\*!?\*?@\*+$/', $lower)) {
            return true;
        }

        return false;
    }

    public static function parseUserHost(string $mask): array
    {
        $atPos = strpos($mask, '@');
        if (false === $atPos) {
            return ['user' => '*', 'host' => $mask];
        }

        $user = substr($mask, 0, $atPos);
        $host = substr($mask, $atPos + 1);

        return [
            'user' => '' !== $user ? $user : '*',
            'host' => '' !== $host ? $host : '*',
        ];
    }
}
