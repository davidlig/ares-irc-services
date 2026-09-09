<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RegisterChannel;

use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelRegisterThrottlePort;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\Shared\Application\Port\EventBusInterface;

use function ceil;
use function count;
use function strtolower;

final readonly class RegisterChannelHandler implements RegisterChannelHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelLevelRepositoryInterface $levelRepository,
        private ChannelRegisterThrottlePort $throttle,
        private EventBusInterface $events,
        private ChanServOperatorAccess $operatorAccess,
        private int $maxChannelsPerNick = 3,
        private int $registerMinIntervalSeconds = 21600,
    ) {}

    public function handle(RegisterChannel $command): RegisterChannelResult
    {
        $channelNameLower = strtolower($command->channelName);
        if ($this->channelRepository->existsByChannelName($channelNameLower)) {
            $existing = $this->channelRepository->findByChannelName($channelNameLower);
            if (null !== $existing && $existing->isPendingDeletion()) {
                return RegisterChannelResult::pendingDeletion();
            }

            throw ChannelAlreadyRegisteredException::forChannel($command->channelName);
        }

        if (!$command->channelExistsOnNetwork) {
            return RegisterChannelResult::channelNotOnNetwork();
        }

        if (null === $command->accountId) {
            return RegisterChannelResult::notIdentified();
        }

        $privileged = $this->operatorAccess->isIrcop(
            $command->actorNickname,
            $command->accountId,
            $command->actorIdentified,
            $command->actorIrcOperator,
        );
        if (!$privileged && !$command->hasRequiredChannelRank) {
            return RegisterChannelResult::insufficientChannelRank();
        }

        if (!$privileged) {
            $remainingSeconds = $this->throttle->getRemainingCooldownSeconds(
                $command->accountId,
                $this->registerMinIntervalSeconds,
            );
            if ($remainingSeconds > 0) {
                return RegisterChannelResult::throttled((int) ceil($remainingSeconds / 60));
            }

            if (count($this->channelRepository->findByFounderNickId($command->accountId)) >= $this->maxChannelsPerNick) {
                return RegisterChannelResult::founderLimitExceeded($this->maxChannelsPerNick);
            }
        }

        $channel = RegisteredChannel::register(
            $command->occurredAt,
            $command->channelName,
            $command->accountId,
            $command->description,
        );
        $this->channelRepository->save($channel);

        if (!$privileged) {
            $this->throttle->recordRegistration($command->accountId);
        }

        foreach (ChannelLevel::DEFAULTS as $key => $value) {
            $this->levelRepository->save(new ChannelLevel($channel->getId(), $key, $value));
        }

        $this->events->dispatch(new ChannelRegisteredEvent(
            $channel->getId(),
            $command->channelName,
            $channelNameLower,
            $command->occurredAt,
        ));

        return RegisterChannelResult::registered();
    }
}
