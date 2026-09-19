<?php

declare(strict_types=1);

namespace App\Irc\Domain\Repository;

use App\Irc\Domain\Network\Channel;
use App\Irc\Domain\ValueObject\ChannelName;

interface ChannelRepositoryInterface
{
    public function save(Channel $channel): void;

    public function remove(ChannelName $name): void;

    public function findByName(ChannelName $name): ?Channel;

    /**
     * @return Channel[]
     */
    public function all(): array;

    /**
     * Yields channels without materializing the full collection; mutation of the repository
     * during iteration yields unspecified results.
     *
     * @return iterable<Channel>
     */
    public function iterateAll(): iterable;

    public function count(): int;
}
