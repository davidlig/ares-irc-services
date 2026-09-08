<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ConfigureMlock;

use App\ChanServ\Application\Port\Out\ChannelMlockStorage;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelMlockUpdatedEvent;

final readonly class ConfigureChannelMlockHandler implements ConfigureChannelMlockHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelMlockStorage $mlockStorage,
        private ChanServEventPublisher $events,
    ) {}

    public function handle(ConfigureChannelMlock $command): ConfigureChannelMlockResult
    {
        $this->mlockStorage->store($command->channel, $command->modeLock);
        $this->channels->save($command->channel);
        $this->events->publish(new ChannelMlockUpdatedEvent($command->channel->getName()));

        return new ConfigureChannelMlockResult($command->modeLock);
    }
}
