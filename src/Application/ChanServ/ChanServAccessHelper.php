<?php

declare(strict_types=1);

namespace App\Application\ChanServ;

use App\Application\Port\ChannelModeSupportInterface;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;

use function in_array;

/**
 * Resolves effective access level and level values for ChanServ commands.
 * Used by command handlers to check permissions (SET, OPDEOP, ACCESSCHANGE, etc.).
 */
final readonly class ChanServAccessHelper
{
    /** Highest to lowest prefix for auto-rank (founder gets highest supported). */
    private const array PREFIX_ORDER = ['q', 'a', 'o', 'h', 'v'];

    public function __construct(
        private ChannelAccessRepositoryInterface $accessRepository,
        private ChannelLevelRepositoryInterface $levelRepository,
    ) {
    }

    public function getLevelValue(int $channelId, string $key): int
    {
        $level = $this->levelRepository->findByChannelAndKey($channelId, $key);

        return null !== $level ? $level->getValue() : ChannelLevel::getDefault($key);
    }

    public function effectiveAccessLevel(RegisteredChannel $channel, int $nickId, bool $isIdentified = true): int
    {
        if (!$isIdentified) {
            return ChannelAccess::LEVEL_UNREGISTERED;
        }
        if ($channel->isFounder($nickId)) {
            return ChannelAccess::FOUNDER_LEVEL;
        }
        $access = $this->accessRepository->findByChannelAndNick($channel->getId(), $nickId);

        return null !== $access ? $access->getLevel() : 0;
    }

    /**
     * Throws InsufficientAccessException if the nick's level is below the required level key.
     */
    public function requireLevel(RegisteredChannel $channel, int $nickId, string $levelKey, string $channelName, string $operation): void
    {
        $required = $this->getLevelValue($channel->getId(), $levelKey);
        $userLevel = $this->effectiveAccessLevel($channel, $nickId);
        if ($userLevel < $required) {
            throw InsufficientAccessException::forOperation($channelName, $operation);
        }
    }

    /**
     * For ACCESS ADD/DEL: user can only manage nicks with level strictly below their own.
     */
    public function canManageLevel(RegisteredChannel $channel, int $managerNickId, int $targetLevel): bool
    {
        $managerLevel = $this->effectiveAccessLevel($channel, $managerNickId);

        return $managerLevel > $targetLevel;
    }

    /**
     * Returns the prefix letter (q/a/o/h/v) the user should have on the channel based on
     * founder status and AUTO* levels, or '' if none. Only returns modes supported by the IRCd.
     */
    public function getDesiredPrefixLetter(RegisteredChannel $channel, int $nickId, ChannelModeSupportInterface $modeSupport): string
    {
        $supported = $modeSupport->getSupportedPrefixModes();
        if ($channel->isFounder($nickId)) {
            foreach (self::PREFIX_ORDER as $letter) {
                if (in_array($letter, $supported, true)) {
                    return $letter;
                }
            }

            return '';
        }

        $level = $this->effectiveAccessLevel($channel, $nickId);
        $channelId = $channel->getId();

        if ($level >= $this->getLevelValue($channelId, ChannelLevel::KEY_AUTOADMIN) && in_array('a', $supported, true)) {
            return 'a';
        }
        if ($level >= $this->getLevelValue($channelId, ChannelLevel::KEY_AUTOOP) && in_array('o', $supported, true)) {
            return 'o';
        }
        if ($level >= $this->getLevelValue($channelId, ChannelLevel::KEY_AUTOHALFOP) && in_array('h', $supported, true)) {
            return 'h';
        }
        if ($level >= $this->getLevelValue($channelId, ChannelLevel::KEY_AUTOVOICE) && in_array('v', $supported, true)) {
            return 'v';
        }

        return '';
    }
}
