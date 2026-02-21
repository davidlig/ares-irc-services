<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Network;

use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\ValueObject\ChannelName;

class InMemoryChannelRepository implements ChannelRepositoryInterface
{
    /** @var array<string, Channel> keyed by lowercase channel name */
    private array $channels = [];

    public function save(Channel $channel): void
    {
        $this->channels[strtolower($channel->name->value)] = $channel;
    }

    public function remove(ChannelName $name): void
    {
        unset($this->channels[strtolower($name->value)]);
    }

    public function findByName(ChannelName $name): ?Channel
    {
        return $this->channels[strtolower($name->value)] ?? null;
    }

    public function all(): array
    {
        return array_values($this->channels);
    }

    public function count(): int
    {
        return count($this->channels);
    }
}
