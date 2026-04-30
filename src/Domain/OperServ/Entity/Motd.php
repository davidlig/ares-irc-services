<?php

declare(strict_types=1);

namespace App\Domain\OperServ\Entity;

use DateTimeImmutable;

class Motd
{
    public const int MAX_TEXT_LENGTH = 400;

    public const string TYPE_PRIVMSG = 'PRIVMSG';

    public const string TYPE_NOTICE = 'NOTICE';

    public const int MAX_BOT_NICKNAME_LENGTH = 128;

    private static int $nextId = 1;

    private ?int $id = null;

    private string $text;

    private bool $enabled = true;

    private string $botNickname;

    private string $messageType;

    private ?int $creatorNickId = null;

    private DateTimeImmutable $createdAt;

    private ?DateTimeImmutable $expiresAt = null;

    private function __construct()
    {
    }

    public static function create(
        string $text,
        string $botNickname,
        string $messageType,
        ?int $creatorNickId = null,
        ?DateTimeImmutable $expiresAt = null,
    ): self {
        $motd = new self();
        $motd->id = self::$nextId++;
        $motd->text = $text;
        $motd->enabled = true;
        $motd->botNickname = $botNickname;
        $motd->messageType = $messageType;
        $motd->creatorNickId = $creatorNickId;
        $motd->createdAt = new DateTimeImmutable();
        $motd->expiresAt = $expiresAt;

        return $motd;
    }

    public function getId(): int
    {
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

    public function isExpired(): bool
    {
        if (null === $this->expiresAt) {
            return false;
        }

        return $this->expiresAt <= new DateTimeImmutable();
    }

    public function clearCreatorNickId(): void
    {
        $this->creatorNickId = null;
    }

    public static function isValidMessageType(string $type): bool
    {
        return self::TYPE_PRIVMSG === $type || self::TYPE_NOTICE === $type;
    }
}
