<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ConfigureSecure;

use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelSecureEnabledEvent;

final readonly class ConfigureChannelSecureHandler implements ConfigureChannelSecureHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChanServEventPublisher $events,
    ) {}

    public function handle(ConfigureChannelSecure $command): void
    {
        $command->channel->configureSecure($command->enabled);
        $this->channels->save($command->channel);

        if ($command->enabled) {
            $this->events->publish(new ChannelSecureEnabledEvent($command->channel->getName()));
        }
    }
}
