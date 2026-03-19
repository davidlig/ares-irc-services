<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

use function fnmatch;
use function sprintf;
use function strlen;

/**
 * Auto-kick entry for a registered channel.
 *
 * When a user matching the mask joins the channel, they are automatically
 * kicked. The +b ban is also set on the channel.
 */
class ChannelAkick
{
    public const int MAX_MASK_LENGTH = 255;

    public const int MAX_REASON_LENGTH = 255;

    public const int MAX_ENTRIES_PER_CHANNEL = 100;

    public const int MIN_ALNUM_CHARS = 4;

    private int $id;

    private int $channelId;

    private int $creatorNickId;

    private string $mask;

    private ?string $reason = null;

    private DateTimeImmutable $createdAt;

    private ?DateTimeImmutable $expiresAt = null;

    private function __construct(
        int $channelId,
        int $creatorNickId,
        string $mask,
        ?string $reason,
        ?DateTimeImmutable $expiresAt,
    ) {
        $this->channelId = $channelId;
        $this->creatorNickId = $creatorNickId;
        $this->setMask($mask);
        $this->setReason($reason);
        $this->createdAt = new DateTimeImmutable();
        $this->expiresAt = $expiresAt;
    }

    public static function create(
        int $channelId,
        int $creatorNickId,
        string $mask,
        ?string $reason = null,
        ?DateTimeImmutable $expiresAt = null,
    ): self {
        return new self($channelId, $creatorNickId, $mask, $reason, $expiresAt);
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getChannelId(): int
    {
        return $this->channelId;
    }

    public function getCreatorNickId(): int
    {
        return $this->creatorNickId;
    }

    public function getMask(): string
    {
        return $this->mask;
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

    /**
     * Checks if a user mask matches this AKICK mask.
     *
     * Uses fnmatch for wildcard matching (* and ?).
     * Converts both masks to lowercase for case-insensitive comparison.
     *
     * @param string $userMask Full mask (nick!user@host) to check against
     */
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

        if (!self::isValidMask($mask)) {
            throw new InvalidArgumentException('Invalid mask format. Must contain at least one ! and one @ (e.g., *!*@*.isp.com).');
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

        return substr_count($mask, '!') >= 1 && substr_count($mask, '@') >= 1;
    }

    public static function isSafeMask(string $mask): bool
    {
        $exclamationPos = strpos($mask, '!');
        if (false === $exclamationPos) {
            return false;
        }

        $nickPart = substr($mask, 0, $exclamationPos);
        $restMask = substr($mask, $exclamationPos + 1);

        // If nick part has alphanumeric chars, the mask is specific enough to be safe
        foreach (str_split($nickPart) as $char) {
            if (ctype_alnum($char)) {
                return true;
            }
        }

        // Nick is only wildcards, check the user@host part for enough alnum chars
        $alnumCount = 0;
        foreach (str_split($restMask) as $char) {
            if (ctype_alnum($char)) {
                ++$alnumCount;
            }
        }

        return $alnumCount >= self::MIN_ALNUM_CHARS;
    }
}
