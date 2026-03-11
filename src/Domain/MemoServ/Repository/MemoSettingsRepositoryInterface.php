<?php

declare(strict_types=1);

namespace App\Domain\MemoServ\Repository;

use App\Domain\MemoServ\Entity\MemoSettings;

interface MemoSettingsRepositoryInterface
{
    public function save(MemoSettings $settings): void;

    public function delete(MemoSettings $settings): void;

    public function findByTargetNick(int $nickId): ?MemoSettings;

    public function findByTargetChannel(int $channelId): ?MemoSettings;

    /**
     * True if memos are enabled for this nick. No row = enabled.
     */
    public function isEnabledForNick(int $nickId): bool;

    /**
     * True if memos are enabled for this channel. No row = enabled.
     */
    public function isEnabledForChannel(int $channelId): bool;

    /**
     * Remove settings for this nick.
     */
    public function deleteAllForNick(int $nickId): void;

    /**
     * Remove settings for this channel.
     */
    public function deleteAllForChannel(int $channelId): void;
}
