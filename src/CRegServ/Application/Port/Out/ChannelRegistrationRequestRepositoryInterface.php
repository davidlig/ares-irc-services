<?php

declare(strict_types=1);

namespace App\CRegServ\Application\Port\Out;

use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;

interface ChannelRegistrationRequestRepositoryInterface
{
    public function save(ChannelRegistrationRequest $request): void;

    /**
     * The single request for a channel, whatever its status, or null.
     *
     * The channel is matched case-insensitively, so the caller passes it as typed.
     *
     * Channels hold at most one record, so a denied request still blocks a new
     * one until a services admin drops it.
     */
    public function findByChannel(string $channelLower): ?ChannelRegistrationRequest;

    /**
     * The requester's last request, used for the registration throttle.
     */
    public function findLastRequestByAccount(int $requesterAccountId): ?ChannelRegistrationRequest;

    /**
     * @param ChannelRegistrationStatus[] $statuses
     *
     * @return ChannelRegistrationRequest[] newest first
     */
    public function findByStatuses(array $statuses): array;

    /**
     * The channel names already held by a request record, for one status.
     *
     * @param string[]                    $channelLowers
     * @param ChannelRegistrationStatus[] $statuses
     *
     * @return array<string, ChannelRegistrationRequest> keyed by channel_lower
     */
    public function findByChannels(array $channelLowers, array $statuses): array;

    public function delete(ChannelRegistrationRequest $request): void;
}
