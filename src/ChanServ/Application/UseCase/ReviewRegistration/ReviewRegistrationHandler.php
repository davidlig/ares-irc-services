<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ReviewRegistration;

use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\PublishedEvent\ChannelRegistrationDecisionEvent;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\Shared\Application\Port\EventBusInterface;
use InvalidArgumentException;

use function count;
use function sprintf;

final readonly class ReviewRegistrationHandler implements ReviewRegistrationHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelRegistrationActivator $activator,
        private EventBusInterface $events,
        private ChannelHistoryService $history,
        private ChanUserAccountPort $accounts,
        private ChanTransactionBoundary $transactionBoundary,
        private int $maxChannelsPerNick = 3,
        private int $rejectionCooldownHours = 24,
    ) {
        if ($rejectionCooldownHours < 0) {
            throw new InvalidArgumentException('Channel registration rejection cooldown cannot be negative.');
        }
    }

    public function handle(ReviewRegistration $command): ReviewRegistrationResult
    {
        $channel = $this->channels->findByChannelName($command->channelName);
        if (null === $channel || !$channel->isPendingRegistration()) {
            return new ReviewRegistrationResult(ReviewRegistrationOutcome::NotPending);
        }

        $accepted = null === $command->rejectionReason;
        if ($accepted && !$command->channelExistsOnNetwork) {
            return new ReviewRegistrationResult(ReviewRegistrationOutcome::ChannelNotOnNetwork);
        }

        $requesterId = $channel->getFounderNickId();
        if ($accepted) {
            $requester = $this->accounts->findAccountById($requesterId);
            if (null === $requester || !$requester->registered || $requester->suspended) {
                return new ReviewRegistrationResult(ReviewRegistrationOutcome::RequesterInactive);
            }
            if (!$command->requesterHasRequiredChannelRank) {
                return new ReviewRegistrationResult(ReviewRegistrationOutcome::RequesterIneligible);
            }
            $registeredCount = count(array_filter(
                $this->channels->findByFounderNickId($requesterId),
                static fn ($owned): bool => $owned->getId() !== $channel->getId(),
            ));
            if ($registeredCount >= $this->maxChannelsPerNick) {
                return new ReviewRegistrationResult(ReviewRegistrationOutcome::FounderLimitExceeded);
            }
        }

        $restrictedUntil = null;
        if ($accepted) {
            $channel->approveRegistration($command->occurredAt);
        } else {
            $restrictedUntil = 0 === $this->rejectionCooldownHours
                ? null
                : $command->occurredAt->modify(sprintf('+%d hours', $this->rejectionCooldownHours));
            $channel->rejectRegistration(
                $command->rejectionReason,
                $command->operatorAccountId,
                $command->operatorNickname,
                $command->occurredAt,
                $restrictedUntil,
            );
        }

        $restrictedUntil = $channel->getRegistrationRestrictedUntil();
        $this->transactionBoundary->transactional(function () use ($channel, $accepted, $command): void {
            if ($accepted) {
                $this->activator->activate($channel);
            }

            $this->channels->save($channel);
            if ($accepted) {
                $this->history->recordWithExplicitMetadata(
                    channelId: $channel->getId(),
                    action: 'CREG_ACCEPT',
                    performedBy: $command->operatorNickname,
                    performedByNickId: $command->operatorAccountId,
                    performedAt: $command->occurredAt,
                    message: 'history.message.registration_approved',
                );
            }
        });
        $this->events->dispatch(new ChannelRegistrationDecisionEvent(
            $channel->getId(),
            $channel->getName(),
            $requesterId,
            $command->operatorAccountId,
            $command->operatorNickname,
            $accepted,
            $accepted ? null : $command->rejectionReason,
            $accepted ? null : $restrictedUntil,
            $command->occurredAt,
        ));

        if ($accepted) {
            $this->events->dispatch(new ChannelRegisteredEvent(
                $channel->getId(),
                $channel->getName(),
                $channel->getNameLower(),
                $command->occurredAt,
            ));
        }

        return new ReviewRegistrationResult($accepted ? ReviewRegistrationOutcome::Accepted : ReviewRegistrationOutcome::Rejected);
    }
}
