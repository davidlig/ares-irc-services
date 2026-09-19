<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ShowInfo;

use DateTimeImmutable;

final readonly class ChannelInfoView
{
    public function __construct(
        public int $founderAccountId,
        public string $founderName,
        public ?string $successorName,
        public string $description,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $lastUsedAt,
        public ?string $url,
        public ?string $email,
        public ?string $topic,
        public ?string $lastTopicSetByNick,
        public bool $topicLock,
        public bool $mlockActive,
        public string $mlock,
        public bool $secure,
        public bool $noExpire,
        public bool $forbidden,
        public ?string $forbiddenReason,
        public bool $pendingDeletion,
        public ?DateTimeImmutable $pendingDeletionAt,
        public ?DateTimeImmutable $pendingDeletionUntil,
        public bool $suspended,
        public ?string $suspendedReason,
        public ?DateTimeImmutable $suspendedUntil,
    ) {}
}
