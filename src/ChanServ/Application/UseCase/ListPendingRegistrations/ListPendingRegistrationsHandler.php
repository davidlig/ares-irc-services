<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ListPendingRegistrations;

use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

use function array_values;
use function max;

final readonly class ListPendingRegistrationsHandler
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChanUserAccountPort $accounts,
    ) {}

    public function find(string $channelName): ?PendingRegistrationView
    {
        $channel = $this->channels->findByChannelName($channelName);
        if (null === $channel || !$channel->isPendingRegistration()) {
            return null;
        }

        return new PendingRegistrationView(
            $channel->getName(),
            $channel->getFounderNickId(),
            $this->accounts->findNicknameById($channel->getFounderNickId()),
            $channel->getDescription(),
            $channel->getCreatedAt(),
        );
    }

    /** @return list<PendingRegistrationView> */
    public function page(int $page, int $pageSize = 20): array
    {
        $offset = ($page - 1) * $pageSize;
        $found = [];
        $channels = $this->channels->findPendingPage(max(0, $offset), max(1, $pageSize));
        $ids = [];
        foreach ($channels as $channel) {
            $ids[$channel->getFounderNickId()] = $channel->getFounderNickId();
        }
        $nicknames = [] === $ids ? [] : $this->accounts->findNicknamesByIds(array_values($ids));
        foreach ($channels as $channel) {
            $found[] = new PendingRegistrationView(
                $channel->getName(),
                $channel->getFounderNickId(),
                $nicknames[$channel->getFounderNickId()] ?? null,
                $channel->getDescription(),
                $channel->getCreatedAt(),
            );
        }

        return $found;
    }
}
