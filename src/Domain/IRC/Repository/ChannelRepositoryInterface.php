<?php

declare(strict_types=1);

namespace App\Domain\IRC\Repository;

use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\ValueObject\ChannelName;

interface ChannelRepositoryInterface
{
    public function save(Channel $channel): void;

    public function remove(ChannelName $name): void;

    public function findByName(ChannelName $name): ?Channel;

    /**
     * @return Channel[]
     */
    public function all(): array;

    public function count(): int;
}
