<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ApplyStoredTopic;

use App\ChanServ\Application\Port\Out\ChannelTopicActions;
use App\ChanServ\Application\Port\Out\ChannelTopicNetworkQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;

final readonly class ApplyStoredChannelTopicHandler implements ApplyStoredChannelTopicHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelTopicNetworkQuery $network,
        private ChannelTopicActions $actions,
    ) {}

    public function handle(ApplyStoredChannelTopic $command): void
    {
        if (StoredTopicApplicationTrigger::NetworkSynchronizationCompleted === $command->trigger) {
            $this->synchronizeAll();

            return;
        }

        $registered = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $registered || $registered->isBlocked()) {
            return;
        }

        $storedTopic = $registered->getTopic();
        if (null === $storedTopic) {
            return;
        }

        if ($command->channelSetupApplicable) {
            $this->actions->setTopic($command->channelName, $storedTopic);

            return;
        }

        $network = $this->network->findChannel($command->channelName);
        if (null !== $network && $storedTopic === $network->topic) {
            return;
        }

        $this->actions->setTopic($command->channelName, $storedTopic);
    }

    private function synchronizeAll(): void
    {
        foreach ($this->channels->listAll() as $registered) {
            $this->synchronize($registered);
        }
    }

    private function synchronize(RegisteredChannel $registered): void
    {
        if ($registered->isBlocked()) {
            return;
        }

        $storedTopic = $registered->getTopic();
        if (null === $storedTopic) {
            return;
        }

        $network = $this->network->findChannel($registered->getName());
        if (null === $network || $storedTopic === $network->topic) {
            return;
        }

        $this->actions->setTopic($network->channelName, $storedTopic);
    }
}
