<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

interface FounderChangeTokenPort
{
    public function store(int $channelId, int $newFounderNickId, string $token, \DateTimeImmutable $expiresAt): void;

    public function consume(int $channelId, string $token): ?int;

    public function getLastRequestAt(int $channelId): ?\DateTimeImmutable;

    public function recordRequest(int $channelId): void;
}
