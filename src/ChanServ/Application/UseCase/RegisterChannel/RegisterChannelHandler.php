<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\RegisterChannel;

use App\ChanServ\Application\Port\Out\ChannelRegisterThrottlePort;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegistrationModeQuery;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use App\Shared\Application\Port\EventBusInterface;

use function array_filter;
use function ceil;
use function count;
use function strtolower;

final readonly class RegisterChannelHandler implements RegisterChannelHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelRegistrationActivator $activator,
        private ChannelRegisterThrottlePort $throttle,
        private EventBusInterface $events,
        private ChanServOperatorAccess $operatorAccess,
        private ChanTransactionBoundary $transactionBoundary,
        private int $maxChannelsPerNick = 3,
        private int $registerMinIntervalSeconds = 21600,
        private ?RegistrationModeQuery $registrationMode = null,
    ) {}

    public function handle(RegisterChannel $command): RegisterChannelResult
    {
        $channelNameLower = strtolower($command->channelName);
        $expiredRejectedChannel = null;
        if ($this->channelRepository->existsByChannelName($channelNameLower)) {
            $existing = $this->channelRepository->findByChannelName($channelNameLower);
            if (null !== $existing && $existing->isPendingDeletion()) {
                return RegisterChannelResult::pendingDeletion();
            }
            if (null !== $existing && $existing->isRejectedRegistration()) {
                $restrictedUntil = $existing->getRegistrationRestrictedUntil();
                if (null === $restrictedUntil || $command->occurredAt < $restrictedUntil) {
                    return RegisterChannelResult::registrationRestricted($restrictedUntil);
                }
                $expiredRejectedChannel = $existing;
            } elseif (null !== $existing) {
                throw ChannelAlreadyRegisteredException::forChannel($command->channelName);
            } else {
                throw ChannelAlreadyRegisteredException::forChannel($command->channelName);
            }
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

            $ownedChannels = $this->channelRepository->findByFounderNickId($command->accountId);
            $registeredCount = count(array_filter(
                $ownedChannels,
                fn (RegisteredChannel $owned): bool => null === $expiredRejectedChannel || $owned->getId() !== $expiredRejectedChannel->getId(),
            ));
            if ($registeredCount >= $this->maxChannelsPerNick) {
                return RegisterChannelResult::founderLimitExceeded($this->maxChannelsPerNick);
            }
        }

        $channel = RegistrationMode::Approval === ($this->registrationMode?->current() ?? RegistrationMode::Direct)
            ? RegisteredChannel::createPendingRegistration(
                $command->occurredAt,
                $command->channelName,
                $command->accountId,
                $command->description,
            )
            : RegisteredChannel::register(
                $command->occurredAt,
                $command->channelName,
                $command->accountId,
                $command->description,
            );
        if (null === $expiredRejectedChannel) {
            $this->channelRepository->save($channel);
        } else {
            $this->transactionBoundary->transactional(function () use ($expiredRejectedChannel, $channel): void {
                $this->channelRepository->delete($expiredRejectedChannel);
                $this->channelRepository->save($channel);
            });
        }

        if ($channel->isPendingRegistration()) {
            if (!$privileged) {
                $this->throttle->recordRegistration($command->accountId);
            }

            return RegisterChannelResult::awaitingApproval();
        }

        $this->activator->activate($channel);

        if (!$privileged) {
            $this->throttle->recordRegistration($command->accountId);
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
