<?php

declare(strict_types=1);

namespace App\Domain\MemoServ\Repository;

use App\Domain\MemoServ\Entity\Memo;

interface MemoRepositoryInterface
{
    public function save(Memo $memo): void;

    public function delete(Memo $memo): void;

    /**
     * Memos for a nick (inbox), ordered by created_at ascending (oldest first).
     *
     * @return Memo[]
     */
    public function findByTargetNick(int $nickId): array;

    /**
     * Memos for a channel, ordered by created_at ascending.
     *
     * @return Memo[]
     */
    public function findByTargetChannel(int $channelId): array;

    public function countUnreadByTargetNick(int $nickId): int;

    public function countUnreadByTargetChannel(int $channelId): int;

    public function countByTargetNick(int $nickId): int;

    public function countByTargetChannel(int $channelId): int;

    public function findById(int $id): ?Memo;

    /**
     * Nth memo (1-based index) for the nick's inbox. Null if index out of range.
     */
    public function findByTargetNickAndIndex(int $nickId, int $index): ?Memo;

    /**
     * Nth memo (1-based index) for the channel. Null if index out of range.
     */
    public function findByTargetChannelAndIndex(int $channelId, int $index): ?Memo;

    /**
     * Delete all memos where target_nick_id = nickId or sender_nick_id = nickId.
     */
    public function deleteAllForNick(int $nickId): void;

    /**
     * Delete all memos where target_channel_id = channelId.
     */
    public function deleteAllForChannel(int $channelId): void;
}
