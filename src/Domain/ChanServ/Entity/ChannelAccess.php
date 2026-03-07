<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Entity;

use InvalidArgumentException;

use function sprintf;

/**
 * Access list entry: a registered nick has a level (1-499) on a channel.
 * Founder has implicit level 500 and is not stored here.
 */
class ChannelAccess
{
    public const int LEVEL_MIN = 1;

    public const int LEVEL_MAX = 499;

    public const int FOUNDER_LEVEL = 500;

    public const int MAX_ENTRIES_PER_CHANNEL = 100;

    private int $id;

    private int $channelId;

    private int $nickId;

    private int $level;

    public function __construct(
        int $channelId,
        int $nickId,
        int $level,
    ) {
        $this->channelId = $channelId;
        $this->nickId = $nickId;
        $this->level = $level;
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidArgumentException(sprintf('Access level must be between %d and %d.', self::LEVEL_MIN, self::LEVEL_MAX));
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getChannelId(): int
    {
        return $this->channelId;
    }

    public function getNickId(): int
    {
        return $this->nickId;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function updateLevel(int $level): void
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidArgumentException(sprintf('Access level must be between %d and %d.', self::LEVEL_MIN, self::LEVEL_MAX));
        }
        $this->level = $level;
    }
}
