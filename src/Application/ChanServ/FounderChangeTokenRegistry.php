<?php

declare(strict_types=1);

namespace App\Application\ChanServ;

use DateTimeImmutable;

/**
 * In-memory store for SET FOUNDER change tokens.
 * Key: channelId. Value: newFounderNickId, token, expiresAt.
 * Populated when founder runs SET FOUNDER #channel newnick; consumed with token.
 */
final class FounderChangeTokenRegistry
{
    /** @var array<int, array{newFounderNickId: int, token: string, expiresAt: DateTimeImmutable}> */
    private array $entries = [];

    /** @var array<int, DateTimeImmutable> */
    private array $lastRequestAt = [];

    public function store(int $channelId, int $newFounderNickId, string $token, DateTimeImmutable $expiresAt): void
    {
        $this->entries[$channelId] = [
            'newFounderNickId' => $newFounderNickId,
            'token' => $token,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Validates and consumes the token for the channel. Returns new founder nick id or null.
     */
    public function consume(int $channelId, string $token): ?int
    {
        $entry = $this->entries[$channelId] ?? null;
        if (null === $entry) {
            return null;
        }
        if ($entry['expiresAt'] < new DateTimeImmutable()) {
            unset($this->entries[$channelId]);

            return null;
        }
        if (!hash_equals($entry['token'], $token)) {
            return null;
        }
        $newFounderNickId = $entry['newFounderNickId'];
        unset($this->entries[$channelId]);

        return $newFounderNickId;
    }

    public function getLastRequestAt(int $channelId): ?DateTimeImmutable
    {
        return $this->lastRequestAt[$channelId] ?? null;
    }

    public function recordRequest(int $channelId): void
    {
        $this->lastRequestAt[$channelId] = new DateTimeImmutable();
    }
}
