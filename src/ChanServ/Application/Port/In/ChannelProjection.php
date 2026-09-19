<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\In;

final readonly class ChannelProjection
{
    /**
     * @param array<string, string> $mlockParams
     */
    public function __construct(
        public int $id,
        public string $name,
        public int $founderNickId,
        public ?string $topic,
        public bool $mlockActive,
        public string $mlock,
        public array $mlockParams,
        public bool $topicLock,
        public bool $forbidden,
        public ?string $forbiddenReason,
        public bool $suspended,
        public bool $pendingDeletion,
        public ?string $suspensionReason = null,
    ) {}
}
