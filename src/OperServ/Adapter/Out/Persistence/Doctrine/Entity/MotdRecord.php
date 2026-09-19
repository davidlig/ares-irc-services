<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine\Entity;

use DateTimeImmutable;
use LogicException;

/** Doctrine-only representation of a persisted MOTD row. */
class MotdRecord
{
    private int $id = 0;

    private bool $enabled = true;

    private int $shownCount = 0;

    private function __construct(
        private string $text,
        private string $botNickname,
        private string $messageType,
        private ?int $creatorNickId,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $expiresAt,
    ) {}

    public static function create(
        string $text,
        string $botNickname,
        string $messageType,
        ?int $creatorNickId,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $expiresAt,
    ): self {
        return new self($text, $botNickname, $messageType, $creatorNickId, $createdAt, $expiresAt);
    }

    public function getId(): int
    {
        if (0 === $this->id) {
            throw new LogicException('Persisted MOTD entry must have an identifier.');
        }

        return $this->id;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getBotNickname(): string
    {
        return $this->botNickname;
    }

    public function getMessageType(): string
    {
        return $this->messageType;
    }

    public function getCreatorNickId(): ?int
    {
        return $this->creatorNickId;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getShownCount(): int
    {
        return $this->shownCount;
    }

    public function recordShown(): void
    {
        ++$this->shownCount;
    }
}
