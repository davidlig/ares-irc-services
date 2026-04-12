<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Entity;

use DateTimeImmutable;

final class ChannelHistory
{
    private int $id;

    private readonly int $channelId;

    private readonly string $action;

    private readonly string $performedBy;

    private readonly ?int $performedByNickId;

    private readonly DateTimeImmutable $performedAt;

    private readonly string $message;

    private readonly array $extraData;

    public function __construct(
        int $id,
        int $channelId,
        string $action,
        string $performedBy,
        ?int $performedByNickId,
        DateTimeImmutable $performedAt,
        string $message,
        array $extraData = [],
    ) {
        $this->id = $id;
        $this->channelId = $channelId;
        $this->action = $action;
        $this->performedBy = $performedBy;
        $this->performedByNickId = $performedByNickId;
        $this->performedAt = $performedAt;
        $this->message = $message;
        $this->extraData = $extraData;
    }

    public static function record(
        int $channelId,
        string $action,
        string $performedBy,
        ?int $performedByNickId,
        string $message,
        array $extraData = [],
        ?DateTimeImmutable $performedAt = null,
    ): self {
        return new self(
            id: 0,
            channelId: $channelId,
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

    public function getChannelId(): int
    {
        return $this->channelId;
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
            'channel_id' => $this->channelId,
            'action' => $this->action,
            'performed_by' => $this->performedBy,
            'performed_by_nick_id' => $this->performedByNickId,
            'performed_at' => $this->performedAt->format('c'),
            'message' => $this->message,
            'extra_data' => $this->extraData,
        ];
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }
}
