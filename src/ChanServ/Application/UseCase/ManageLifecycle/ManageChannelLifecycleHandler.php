<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageLifecycle;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelSuspendedEvent;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Application\Service\ChannelSuspensionService;
use App\Shared\Application\Port\EventBusInterface;
use App\Shared\Application\Time\RelativeExpiryParser;
use LogicException;

use function strtolower;

final readonly class ManageChannelLifecycleHandler implements ManageChannelLifecycleHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChanDropService $dropService,
        private ChannelForbiddenService $forbiddenService,
        private ChannelSuspensionService $suspensionService,
        private EventBusInterface $events,
    ) {}

    public function handle(ManageChannelLifecycle $command): ChannelLifecycleResult
    {
        return match ($command->action) {
            ChannelLifecycleAction::Drop => $this->drop($command),
            ChannelLifecycleAction::Forbid => $this->forbid($command),
            ChannelLifecycleAction::Unforbid => $this->unforbid($command),
            ChannelLifecycleAction::Restore => $this->restore($command),
            ChannelLifecycleAction::EnableNoExpire => $this->changeNoExpire($command, true),
            ChannelLifecycleAction::DisableNoExpire => $this->changeNoExpire($command, false),
            ChannelLifecycleAction::Suspend => $this->suspend($command),
            ChannelLifecycleAction::Unsuspend => $this->unsuspend($command),
        };
    }

    private function drop(ManageChannelLifecycle $command): ChannelLifecycleResult
    {
        $channel = $this->channels->findByChannelName($command->channelName);
        if (null === $channel) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::NotRegistered);
        }
        if ($channel->isPendingDeletion() && !$command->force) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::PendingDeletion);
        }
        if ($command->force) {
            $this->dropService->hardDropChannel($channel, $command->occurredAt, 'manual-force', $command->actorNickname);

            return new ChannelLifecycleResult(ChannelLifecycleOutcome::ForceDropped);
        }

        $this->dropService->softDropChannel($channel, $command->occurredAt, $command->actorNickname);

        return new ChannelLifecycleResult(ChannelLifecycleOutcome::Dropped);
    }

    private function forbid(ManageChannelLifecycle $command): ChannelLifecycleResult
    {
        $existing = $this->channels->findByChannelName($command->channelName);
        $this->forbiddenService->forbid(
            $command->channelName,
            $command->reason ?? throw new LogicException('A forbid reason is required.'),
            $command->actorNickname,
            $command->occurredAt,
        );

        return new ChannelLifecycleResult(
            null !== $existing && $existing->isForbidden()
                ? ChannelLifecycleOutcome::ForbiddenUpdated
                : ChannelLifecycleOutcome::Forbidden,
        );
    }

    private function unforbid(ManageChannelLifecycle $command): ChannelLifecycleResult
    {
        $unforbidden = $this->forbiddenService->unforbid($command->channelName, $command->actorNickname, $command->occurredAt);

        return new ChannelLifecycleResult(
            $unforbidden ? ChannelLifecycleOutcome::Unforbidden : ChannelLifecycleOutcome::NotForbidden,
        );
    }

    private function restore(ManageChannelLifecycle $command): ChannelLifecycleResult
    {
        $channel = $this->channels->findByChannelName($command->channelName);
        if (null === $channel) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::NotRegistered);
        }
        if (!$channel->isPendingDeletion()) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::NotPendingDeletion);
        }

        $this->dropService->restoreChannel($channel, $command->actorNickname);

        return new ChannelLifecycleResult(ChannelLifecycleOutcome::Restored);
    }

    private function changeNoExpire(ManageChannelLifecycle $command, bool $enabled): ChannelLifecycleResult
    {
        $channel = $this->channels->findByChannelName($command->channelName);
        if (null === $channel) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::NotRegistered);
        }
        if ($channel->isForbidden()) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::ChannelForbidden);
        }
        if ($channel->isSuspended()) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::ChannelSuspended);
        }

        $channel->changeNoExpire($enabled);
        $this->channels->save($channel);

        return new ChannelLifecycleResult(
            $enabled ? ChannelLifecycleOutcome::NoExpireEnabled : ChannelLifecycleOutcome::NoExpireDisabled,
        );
    }

    private function suspend(ManageChannelLifecycle $command): ChannelLifecycleResult
    {
        $channel = $this->channels->findByChannelName($command->channelName);
        if (null === $channel) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::NotRegistered);
        }
        if ($channel->isSuspended()) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::AlreadySuspended);
        }

        $duration = $command->duration ?? '';
        $expiresAt = RelativeExpiryParser::parse($duration, $command->occurredAt);
        if (null === $expiresAt && !RelativeExpiryParser::isPermanent($duration)) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::InvalidDuration);
        }

        $reason = $command->reason ?? throw new LogicException('A suspension reason is required.');
        $channel->suspend($reason, $expiresAt);
        $this->channels->save($channel);
        $this->suspensionService->enforceSuspension($channel);
        $this->events->dispatch(new ChannelSuspendedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            channelNameLower: $channel->getNameLower(),
            reason: $reason,
            duration: '0' === strtolower($duration) ? null : $duration,
            expiresAt: $expiresAt,
            performedBy: $command->actorNickname,
            performedByNickId: $command->actorAccountId,
            performedByIp: $command->actorIp,
            performedByHost: $command->actorHost,
            occurredAt: $command->occurredAt,
        ));

        return new ChannelLifecycleResult(ChannelLifecycleOutcome::Suspended, $expiresAt);
    }

    private function unsuspend(ManageChannelLifecycle $command): ChannelLifecycleResult
    {
        $channel = $this->channels->findByChannelName($command->channelName);
        if (null === $channel) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::NotRegistered);
        }
        if (!$channel->isSuspended()) {
            return new ChannelLifecycleResult(ChannelLifecycleOutcome::NotSuspended);
        }

        $channel->unsuspend();
        $this->channels->save($channel);
        $this->events->dispatch(new ChannelUnsuspendedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            channelNameLower: $channel->getNameLower(),
            performedBy: $command->actorNickname,
            performedByNickId: $command->actorAccountId,
            performedByIp: $command->actorIp,
            performedByHost: $command->actorHost,
            occurredAt: $command->occurredAt,
        ));

        return new ChannelLifecycleResult(ChannelLifecycleOutcome::Unsuspended);
    }
}
