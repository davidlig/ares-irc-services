<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SynchronizeTopic;

use App\ChanServ\Application\Port\Out\ChannelTopicActions;
use App\ChanServ\Application\Port\Out\ChannelTopicClock;
use App\ChanServ\Application\Port\Out\ChannelTopicNetworkQuery;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;

use function str_contains;
use function strtolower;

final readonly class SynchronizeReceivedChannelTopicHandler implements SynchronizeReceivedChannelTopicHandlerInterface
{
    private const float TOPIC_PERSIST_GRACE_SECONDS = 2.0;

    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelTopicNetworkQuery $network,
        private ChannelTopicActions $actions,
        private ChannelTopicClock $clock,
        private string $chanservNick,
        private string $nickservNick,
    ) {}

    public function handle(SynchronizeReceivedChannelTopic $command): void
    {
        $registered = $this->channels->findByChannelName(strtolower($command->channelName));
        if (null === $registered || $registered->isBlocked()) {
            return;
        }

        if ($registered->isTopicLock()) {
            $this->actions->setTopic($command->channelName, $registered->getTopic());

            return;
        }

        if (!$this->network->isSynchronizationCompleted($command->channelName)) {
            return;
        }

        $completedAt = $this->network->synchronizationCompletedAt($command->channelName);
        if (null !== $completedAt && ($this->clock->now() - $completedAt) < self::TOPIC_PERSIST_GRACE_SECONDS) {
            return;
        }

        $setterNickname = $command->setterNickname;
        if (null === $setterNickname && null !== $command->sourceUid) {
            $setterNickname = $this->network->resolveNickname($command->sourceUid);
        }

        if (null !== $setterNickname && $this->isServiceNickname($setterNickname)) {
            $setterNickname = null;
        }

        $registered->updateTopic($command->topic, $command->occurredAt, $setterNickname);
        $this->channels->save($registered);
    }

    private function isServiceNickname(string $nickname): bool
    {
        if (str_contains($nickname, '.')) {
            return true;
        }

        return 0 === strcasecmp($nickname, $this->chanservNick)
            || 0 === strcasecmp($nickname, $this->nickservNick);
    }
}
