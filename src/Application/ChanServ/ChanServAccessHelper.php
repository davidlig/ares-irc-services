<?php

declare(strict_types=1);

namespace App\Application\ChanServ;

use App\ChanServ\Domain\Policy\ChannelAccessPolicy;
use App\ChanServ\Domain\ValueObject\AccessLevel;
use App\Domain\ChanServ\Entity\ChannelLevel;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Exception\InsufficientAccessException;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\ChannelLevelRepositoryInterface;

/**
 * Resolves effective access level and level values for ChanServ commands.
 * Used by command handlers to check permissions (SET, OPDEOP, ACCESSCHANGE, etc.).
 */
final readonly class ChanServAccessHelper
{
    public function __construct(
        private ChannelAccessRepositoryInterface $accessRepository,
        private ChannelLevelRepositoryInterface $levelRepository,
        private ChannelAccessPolicy $accessPolicy = new ChannelAccessPolicy(),
    ) {}

    public function getLevelValue(int $channelId, string $key): int
    {
        $level = $this->levelRepository->findByChannelAndKey($channelId, $key);

        return null !== $level ? $level->getValue() : ChannelLevel::getDefault($key);
    }

    public function effectiveAccessLevel(RegisteredChannel $channel, int $nickId, bool $isIdentified = false): int
    {
        if (!$isIdentified) {
            return $this->accessPolicy->effectiveLevel(false, false, null)->value;
        }
        $founder = $channel->isFounder($nickId);
        if ($founder) {
            return $this->accessPolicy->effectiveLevel(true, true, null)->value;
        }
        $access = $this->accessRepository->findByChannelAndNick($channel->getId(), $nickId);

        return $this->accessPolicy->effectiveLevel(
            identified: true,
            founder: false,
            storedAccess: $access?->getLevel(),
        )->value;
    }

    /**
     * Throws InsufficientAccessException if the nick's level is below the required level key.
     */
    public function requireLevel(RegisteredChannel $channel, int $nickId, string $levelKey, string $channelName, string $operation): void
    {
        $required = $this->getLevelValue($channel->getId(), $levelKey);
        $userLevel = $this->effectiveAccessLevel($channel, $nickId, true);
        if ($userLevel < $required) {
            throw InsufficientAccessException::forOperation($channelName, $operation);
        }
    }

    /**
     * For ACCESS ADD/DEL: user can only manage nicks with level strictly below their own.
     */
    public function canManageLevel(RegisteredChannel $channel, int $managerNickId, int $targetLevel): bool
    {
        $managerLevel = $this->effectiveAccessLevel($channel, $managerNickId, true);

        return $this->accessPolicy->canManageLevel(
            AccessLevel::fromEffective($managerLevel),
            $targetLevel,
        );
    }
}
