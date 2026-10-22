<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\TransferFounder;

use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\FounderRescueTokenValidator;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Domain\Policy\FounderTransferPolicy;
use App\ChanServ\Domain\ValueObject\FounderTransferDecision;
use LogicException;

use function count;
use function trim;

final readonly class TransferChannelFounderHandler implements TransferChannelFounderHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $access,
        private ChanUserAccountPort $accounts,
        private FounderRescueTokenValidator $tokens,
        private ChanServEventPublisher $events,
        private FounderTransferPolicy $policy,
        private int $maxChannelsPerNick = 3,
    ) {}

    public function handle(TransferChannelFounder $command): TransferChannelFounderResult
    {
        $targetNickname = trim($command->targetNickname);
        if ('' === $targetNickname) {
            return new TransferChannelFounderResult(TransferFounderOutcome::MissingTarget);
        }

        $target = $this->accounts->findAccountByNick($targetNickname);
        if (null === $target) {
            return new TransferChannelFounderResult(TransferFounderOutcome::TargetNotFound, $targetNickname);
        }

        $decision = $this->policy->decideTarget(
            currentFounderNickId: $command->channel->getFounderNickId(),
            successorNickId: $command->channel->getSuccessorNickId(),
            targetNickId: $target->id,
            targetRegistered: $target->registered,
            targetSuspended: $target->suspended,
        );
        if (FounderTransferDecision::Allowed !== $decision) {
            return $this->denied($decision, $targetNickname);
        }

        $decision = $this->policy->decideChannelLimit(
            count($this->channels->findByFounderNickId($target->id)),
            $this->maxChannelsPerNick,
        );
        if (FounderTransferDecision::Allowed !== $decision) {
            return $this->denied($decision, $targetNickname);
        }

        if ($command->founderEquivalent) {
            return $this->transfer($command, $target->id, true);
        }

        if (null === $command->actor) {
            return new TransferChannelFounderResult(TransferFounderOutcome::Ignored);
        }
        if (null === $command->token || '' === $command->token) {
            return new TransferChannelFounderResult(TransferFounderOutcome::TokenRequired);
        }
        $validation = $this->tokens->validate($command->channel->getFounderNickId(), $command->token, $command->actor->clientKey);
        if (FounderRescueTokenOutcome::Throttled === $validation->outcome) {
            return new TransferChannelFounderResult(TransferFounderOutcome::Throttled, retryAfterSeconds: $validation->retryAfterSeconds);
        }
        if (FounderRescueTokenOutcome::Unavailable === $validation->outcome) {
            return new TransferChannelFounderResult(TransferFounderOutcome::CredentialUnavailable);
        }
        if (FounderRescueTokenOutcome::Valid !== $validation->outcome) {
            return new TransferChannelFounderResult(TransferFounderOutcome::InvalidToken);
        }

        return $this->transfer($command, $target->id, false);
    }

    private function transfer(
        TransferChannelFounder $command,
        int $newFounderNickId,
        bool $byOperator,
    ): TransferChannelFounderResult {
        if (null === $command->actor) {
            return new TransferChannelFounderResult(TransferFounderOutcome::Ignored);
        }

        $channel = $command->channel;
        $oldFounderNickId = $channel->getFounderNickId();
        $channel->changeFounder($newFounderNickId);
        $this->channels->save($channel);

        $existingAccess = $this->access->findByChannelAndNick($channel->getId(), $newFounderNickId);
        if (null !== $existingAccess) {
            $this->access->remove($existingAccess);
        }

        $this->events->publish(new ChannelFounderChangedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            oldFounderNickId: $oldFounderNickId,
            newFounderNickId: $newFounderNickId,
            performedBy: $command->actor->nickname,
            performedByNickId: $command->actor->registeredNickId,
            performedByIp: $command->actor->ipAddress,
            performedByHost: $command->actor->hostmask,
            byOperator: $byOperator,
            occurredAt: $command->requestedAt,
        ));

        $newFounder = $this->accounts->findAccountById($newFounderNickId);

        return new TransferChannelFounderResult(
            TransferFounderOutcome::Updated,
            targetNickname: $newFounder->nickname ?? (string) $newFounderNickId,
        );
    }

    private function denied(FounderTransferDecision $decision, string $targetNickname): TransferChannelFounderResult
    {
        $outcome = match ($decision) {
            FounderTransferDecision::TargetSuspended => TransferFounderOutcome::TargetSuspended,
            FounderTransferDecision::TargetNotRegistered => TransferFounderOutcome::TargetNotRegistered,
            FounderTransferDecision::SameFounder => TransferFounderOutcome::SameFounder,
            FounderTransferDecision::TargetIsSuccessor => TransferFounderOutcome::TargetIsSuccessor,
            FounderTransferDecision::ChannelLimitReached => TransferFounderOutcome::ChannelLimitReached,
            FounderTransferDecision::Allowed => throw new LogicException('Allowed transfers cannot be denied.'),
        };

        return new TransferChannelFounderResult(
            $outcome,
            $targetNickname,
            maximumChannelsPerNick: $this->maxChannelsPerNick,
        );
    }
}
