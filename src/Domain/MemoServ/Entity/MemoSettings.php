<?php

declare(strict_types=1);

namespace App\Domain\MemoServ\Entity;

use InvalidArgumentException;

/**
 * Whether memo reception is enabled for a nick or channel.
 * No row = enabled by default. One row per nick or per channel.
 */
class MemoSettings
{
    private int $id;

    private ?int $targetNickId = null;

    private ?int $targetChannelId = null;

    private bool $enabled;

    public function __construct(
        ?int $targetNickId,
        ?int $targetChannelId,
        bool $enabled,
    ) {
        $nickSet = null !== $targetNickId;
        $channelSet = null !== $targetChannelId;
        if ($nickSet === $channelSet) {
            throw new InvalidArgumentException('Exactly one of targetNickId or targetChannelId must be set.');
        }
        $this->targetNickId = $targetNickId;
        $this->targetChannelId = $targetChannelId;
        $this->enabled = $enabled;
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Used by Doctrine hydration; do not call directly.
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }
}
