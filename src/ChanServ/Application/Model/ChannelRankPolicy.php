<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Model;

use App\ChanServ\Domain\ValueObject\ChannelLevelSet;

final readonly class ChannelRankPolicy
{
    /**
     * @param array<int, int> $accessByNickId
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $founderNickId,
        public bool $secure,
        public bool $blocked,
        public ChannelLevelSet $levels,
        private array $accessByNickId,
    ) {}

    public function isFounder(?int $nickId): bool
    {
        return null !== $nickId && $this->founderNickId === $nickId;
    }

    public function storedAccessFor(?int $nickId): ?int
    {
        if (null === $nickId) {
            return null;
        }

        return $this->accessByNickId[$nickId] ?? null;
    }
}
