<?php

declare(strict_types=1);

namespace App\MemoServ\Domain\Entity;

use DateTimeImmutable;
use InvalidArgumentException;

use function sprintf;
use function strlen;

/**
 * A memo sent to a nickname or a channel.
 * Exactly one recipient and exactly one sender representation must be set.
 */
class Memo
{
    public const int MESSAGE_MAX_LENGTH = 255;

    private int $id;

    private ?int $targetNickId = null;

    private ?int $targetChannelId = null;

    private ?int $senderNickId;

    private ?string $senderName;

    private string $message;

    private DateTimeImmutable $createdAt;

    private ?DateTimeImmutable $readAt = null;

    public function __construct(
        ?int $targetNickId,
        ?int $targetChannelId,
        ?int $senderNickId,
        string $message,
        DateTimeImmutable $createdAt,
        ?string $senderName = null,
    ) {
        $nickSet = null !== $targetNickId;
        $channelSet = null !== $targetChannelId;
        if ($nickSet === $channelSet) {
            throw new InvalidArgumentException('Exactly one of targetNickId or targetChannelId must be set.');
        }
        if ((null === $senderNickId) === (null === $senderName)) {
            throw new InvalidArgumentException('Exactly one sender representation must be set.');
        }
        if (null !== $senderName && '' === $senderName) {
            throw new InvalidArgumentException('Service sender name cannot be empty.');
        }
        $this->targetNickId = $targetNickId;
        $this->targetChannelId = $targetChannelId;
        $this->senderNickId = $senderNickId;
        $this->senderName = $senderName;
        self::assertValidMessage($message);
        $this->message = $message;
        $this->createdAt = $createdAt;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getTargetNickId(): ?int
    {
        return $this->targetNickId;
    }

    public function getTargetChannelId(): ?int
    {
        return $this->targetChannelId;
    }

    public function getSenderNickId(): ?int
    {
        return $this->senderNickId;
    }

    public function getSenderName(): ?string
    {
        return $this->senderName;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getReadAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }

    public function isRead(): bool
    {
        return null !== $this->readAt;
    }

    public function markAsRead(DateTimeImmutable $at): void
    {
        $this->readAt = $at;
    }

    private static function assertValidMessage(string $message): void
    {
        if (strlen($message) > self::MESSAGE_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('Message cannot exceed %d characters.', self::MESSAGE_MAX_LENGTH));
        }
    }
}
