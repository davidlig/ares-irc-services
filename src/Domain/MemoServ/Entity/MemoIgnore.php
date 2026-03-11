<?php

declare(strict_types=1);

namespace App\Domain\MemoServ\Entity;

use InvalidArgumentException;

/**
 * Ignore list entry: a nick or channel ignores memos from another nick.
 * For nick: targetNickId set, targetChannelId null.
 * For channel: targetChannelId set, targetNickId null.
 */
class MemoIgnore
{
    private int $id;

    private ?int $targetNickId = null;

    private ?int $targetChannelId = null;

    private int $ignoredNickId;

    public function __construct(
        ?int $targetNickId,
        ?int $targetChannelId,
        int $ignoredNickId,
    ) {
        $nickSet = null !== $targetNickId;
        $channelSet = null !== $targetChannelId;
        if ($nickSet === $channelSet) {
            throw new InvalidArgumentException('Exactly one of targetNickId or targetChannelId must be set.');
        }
        $this->targetNickId = $targetNickId;
        $this->targetChannelId = $targetChannelId;
        $this->ignoredNickId = $ignoredNickId;
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

    public function getIgnoredNickId(): int
    {
        return $this->ignoredNickId;
    }

    /**
     * Used by Doctrine hydration; do not call directly.
     */
    public function setId(int $id): void
    {
        $this->id = $id;
    }
}
