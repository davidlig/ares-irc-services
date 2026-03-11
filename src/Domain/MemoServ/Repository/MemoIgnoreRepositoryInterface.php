<?php

declare(strict_types=1);

namespace App\Domain\MemoServ\Repository;

use App\Domain\MemoServ\Entity\MemoIgnore;

interface MemoIgnoreRepositoryInterface
{
    public function save(MemoIgnore $ignore): void;

    public function delete(MemoIgnore $ignore): void;

    public function findByTargetNickAndIgnored(int $targetNickId, int $ignoredNickId): ?MemoIgnore;

    public function findByTargetChannelAndIgnored(int $targetChannelId, int $ignoredNickId): ?MemoIgnore;

    /**
     * @return MemoIgnore[]
     */
    public function listByTargetNick(int $targetNickId): array;

    /**
     * @return MemoIgnore[]
     */
    public function listByTargetChannel(int $targetChannelId): array;

    public function countByTargetNick(int $targetNickId): int;

    public function countByTargetChannel(int $targetChannelId): int;

    /**
     * Remove all entries where target_nick_id = nickId or ignored_nick_id = nickId.
     */
    public function deleteAllForNick(int $nickId): void;

    /**
     * Remove all entries where target_channel_id = channelId.
     */
    public function deleteAllForChannel(int $channelId): void;
}
