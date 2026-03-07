<?php

declare(strict_types=1);

namespace App\Domain\ChanServ\Entity;

use InvalidArgumentException;

use function sprintf;

/**
 * Level configuration for a channel (AUTOOP, ACCESSLIST, etc.).
 * Value is the minimum access level required; -1 for unregistered, 0 for registered.
 */
class ChannelLevel
{
    public const int LEVEL_MIN = -1;

    public const int LEVEL_MAX = 499;

    private int $id;

    /** Level keys for rank modes (only shown if IRCd supports that mode). */
    public const string KEY_AUTOADMIN = 'AUTOADMIN';

    public const string KEY_AUTOOP = 'AUTOOP';

    public const string KEY_AUTOHALFOP = 'AUTOHALFOP';

    public const string KEY_AUTOVOICE = 'AUTOVOICE';

    /** Level keys for ChanServ commands. */
    public const string KEY_SET = 'SET';

    public const string KEY_ADMINDEADMIN = 'ADMINDEADMIN';

    public const string KEY_OPDEOP = 'OPDEOP';

    public const string KEY_HALFOPDEHALFOP = 'HALFOPDEHALFOP';

    public const string KEY_VOICEDEVOICE = 'VOICEDEVOICE';

    public const string KEY_INVITE = 'INVITE';

    public const string KEY_ACCESSLIST = 'ACCESSLIST';

    public const string KEY_ACCESSCHANGE = 'ACCESSCHANGE';

    /** Default values per key (used on LEVELS RESET and new channel). */
    public const array DEFAULTS = [
        self::KEY_AUTOADMIN => 400,
        self::KEY_AUTOOP => 300,
        self::KEY_AUTOHALFOP => 200,
        self::KEY_AUTOVOICE => 100,
        self::KEY_SET => 499,
        self::KEY_ADMINDEADMIN => 400,
        self::KEY_OPDEOP => 300,
        self::KEY_HALFOPDEHALFOP => 200,
        self::KEY_VOICEDEVOICE => 100,
        self::KEY_INVITE => 200,
        self::KEY_ACCESSLIST => 400,
        self::KEY_ACCESSCHANGE => 499,
    ];

    private int $channelId;

    private string $levelKey;

    private int $value;

    public function __construct(
        int $channelId,
        string $levelKey,
        int $value,
    ) {
        $this->channelId = $channelId;
        $this->levelKey = $levelKey;
        $this->value = $value;
        if ($value < self::LEVEL_MIN || $value > self::LEVEL_MAX) {
            throw new InvalidArgumentException(sprintf('Level value must be between %d and %d.', self::LEVEL_MIN, self::LEVEL_MAX));
        }
    }

    public function getChannelId(): int
    {
        return $this->channelId;
    }

    public function getLevelKey(): string
    {
        return $this->levelKey;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function updateLevelValue(int $value): void
    {
        if ($value < self::LEVEL_MIN || $value > self::LEVEL_MAX) {
            throw new InvalidArgumentException(sprintf('Level value must be between %d and %d.', self::LEVEL_MIN, self::LEVEL_MAX));
        }
        $this->value = $value;
    }

    public static function getDefault(string $levelKey): int
    {
        return self::DEFAULTS[$levelKey] ?? 0;
    }
}
