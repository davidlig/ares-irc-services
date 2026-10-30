<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\SaregisterChannel;

use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelRegisteredEvent;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Shared\Application\Port\EventBusInterface;

use function preg_match;
use function strtolower;
use function trim;

final readonly class SaregisterChannelHandler implements SaregisterChannelHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChanUserAccountPort $accounts,
        private ChannelRegistrationActivator $activator,
        private EventBusInterface $events,
        private ChannelHistoryService $history,
        private ChanTransactionBoundary $transactionBoundary,
    ) {}

    public function handle(SaregisterChannel $command): SaregisterChannelResult
    {
        if (1 !== preg_match('/^#[^\s,\x00-\x1f\x7f]{1,63}$/D', $command->channelName)) {
            return SaregisterChannelResult::invalidChannelName($command->channelName);
        }

        $channelNameLower = strtolower($command->channelName);
        $expiredRejectedChannel = null;
        if ($this->channelRepository->existsByChannelName($channelNameLower)) {
            $existing = $this->channelRepository->findByChannelName($channelNameLower);
            if (null !== $existing && $existing->isPendingDeletion()) {
                return SaregisterChannelResult::pendingDeletion($command->channelName);
            }
            if (null !== $existing && $existing->isRejectedRegistration()) {
                $restrictedUntil = $existing->getRegistrationRestrictedUntil();
                if (null !== $restrictedUntil && $command->occurredAt < $restrictedUntil) {
                    return SaregisterChannelResult::alreadyRegistered($command->channelName);
                }
                $expiredRejectedChannel = $existing;
            } else {
                return SaregisterChannelResult::alreadyRegistered($command->channelName);
            }
        }

        $targetNickname = trim($command->targetNickname);
        $target = $this->accounts->findAccountByNick($targetNickname);
        if (null === $target) {
            return SaregisterChannelResult::targetNotFound($targetNickname);
        }

        if (!$target->registered) {
            return SaregisterChannelResult::targetNotRegistered($targetNickname);
        }

        if ($target->suspended) {
            return SaregisterChannelResult::targetSuspended($targetNickname);
        }

        $channel = RegisteredChannel::register(
            $command->occurredAt,
            $command->channelName,
            $target->id,
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

        $this->activator->activate($channel);

        $this->events->dispatch(new ChannelRegisteredEvent(
            $channel->getId(),
            $command->channelName,
            $channelNameLower,
            $command->occurredAt,
        ));

        $this->history->recordWithExplicitMetadata(
            channelId: $channel->getId(),
            action: 'SAREGISTER',
            performedBy: $command->actorNickname,
            performedByNickId: $command->actorAccountId,
            performedAt: $command->occurredAt,
            message: 'history.message.saregister',
            extraData: [
                'founder' => $target->nickname,
                'founder_id' => $target->id,
            ],
        );

        return SaregisterChannelResult::registered($command->channelName, $target->nickname);
    }
}
