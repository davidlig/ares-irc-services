<?php

declare(strict_types=1);

namespace App\Domain\NickServ\Entity;

use DateTimeImmutable;

/**
 * Immutable record of an action performed on a registered nickname.
 *
 * Tracks both manual entries (HISTORY ADD) and automatic entries
 * (SUSPEND, UNSUSPEND, SET_PASSWORD, etc.) for audit and history purposes.
 */
final class NickHistory
{
    private int $id;

    private readonly int $nickId;

    private readonly string $action;

    private readonly string $performedBy;

    private readonly ?int $performedByNickId;

    private readonly DateTimeImmutable $performedAt;

    private readonly string $message;

    private readonly array $extraData;

    public function __construct(
        int $id,
        int $nickId,
        string $action,
        string $performedBy,
        ?int $performedByNickId,
        DateTimeImmutable $performedAt,
        string $message,
        array $extraData = [],
    ) {
        $this->id = $id;
        $this->nickId = $nickId;
        $this->action = $action;
        $this->performedBy = $performedBy;
        $this->performedByNickId = $performedByNickId;
        $this->performedAt = $performedAt;
        $this->message = $message;
        $this->extraData = $extraData;
    }

    public static function record(
        int $nickId,
        string $action,
        string $performedBy,
        ?int $performedByNickId,
        string $message,
        array $extraData = [],
        ?DateTimeImmutable $performedAt = null,
    ): self {
        return new self(
            id: 0,
            nickId: $nickId,
            action: $action,
            performedBy: $performedBy,
            performedByNickId: $performedByNickId,
            performedAt: $performedAt ?? new DateTimeImmutable(),
            message: $message,
            extraData: $extraData,
        );
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getNickId(): int
    {
        return $this->nickId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getPerformedBy(): string
    {
        return $this->performedBy;
    }

    public function getPerformedByNickId(): ?int
    {
        return $this->performedByNickId;
    }

    public function getPerformedAt(): DateTimeImmutable
    {
        return $this->performedAt;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getExtraData(): array
    {
        return $this->extraData;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'nick_id' => $this->nickId,
            'action' => $this->action,
            'performed_by' => $this->performedBy,
            'performed_by_nick_id' => $this->performedByNickId,
            'performed_at' => $this->performedAt->format('c'),
            'message' => $this->message,
            'extra_data' => $this->extraData,
        ];
    }

    /**
     * Used by Doctrine hydration; do not call directly.
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }
}
