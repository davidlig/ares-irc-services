<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ListPendingRegistrations;

use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

use function count;

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
        $index = 0;
        foreach ($this->channels->iterateAll() as $channel) {
            if (!$channel->isPendingRegistration()) {
                continue;
            }
            if ($index++ < $offset) {
                continue;
            }
            $found[] = new PendingRegistrationView(
                $channel->getName(),
                $channel->getFounderNickId(),
                $this->accounts->findNicknameById($channel->getFounderNickId()),
                $channel->getDescription(),
                $channel->getCreatedAt(),
            );
            if ($pageSize <= count($found)) {
                break;
            }
        }

        return $found;
    }
}
