<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\TransferFounder;

use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\FounderChangeMailSender;
use App\ChanServ\Application\Port\Out\FounderChangeTokenGenerator;
use App\ChanServ\Application\Port\Out\FounderChangeTokenPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Domain\Policy\FounderTransferPolicy;
use App\ChanServ\Domain\ValueObject\FounderTransferDecision;
use App\Shared\Application\EmailHintMasker;
use LogicException;
use Throwable;

use function count;
use function sprintf;
use function trim;

final readonly class TransferChannelFounderHandler implements TransferChannelFounderHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAccessRepositoryInterface $access,
        private ChanUserAccountPort $accounts,
        private FounderChangeTokenPort $tokens,
        private ChanServEventPublisher $events,
        private FounderChangeTokenGenerator $tokenGenerator,
        private FounderChangeMailSender $mailSender,
        private FounderTransferPolicy $policy,
        private int $founderTokenTtlSeconds = 3600,
        private int $founderMinIntervalSeconds = 600,
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

        return $this->handleTokenFlow($command, $target, $targetNickname);
    }

    private function handleTokenFlow(
        TransferChannelFounder $command,
        ChanAccountView $target,
        string $targetNickname,
    ): TransferChannelFounderResult {
        $currentFounder = $this->accounts->findAccountById($command->channel->getFounderNickId());
        $founderEmail = $currentFounder?->email;
        if (null === $founderEmail || '' === $founderEmail) {
            return new TransferChannelFounderResult(TransferFounderOutcome::CurrentFounderWithoutEmail);
        }

        if (null === $command->token || '' === $command->token) {
            return $this->requestToken($command, $target, $targetNickname, $founderEmail);
        }

        return $this->consumeToken($command);
    }

    private function requestToken(
        TransferChannelFounder $command,
        ChanAccountView $target,
        string $targetNickname,
        string $founderEmail,
    ): TransferChannelFounderResult {
        $channelId = $command->channel->getId();
        $lastAt = $this->tokens->getLastRequestAt($channelId);
        if (null !== $lastAt && 0 < $this->founderMinIntervalSeconds) {
            $nextAllowed = $lastAt->modify(sprintf('+%d seconds', $this->founderMinIntervalSeconds));
            if ($command->requestedAt < $nextAllowed) {
                return new TransferChannelFounderResult(TransferFounderOutcome::Throttled);
            }
        }

        $token = $this->tokenGenerator->generate();
        $expiresAt = $command->requestedAt->modify(sprintf('+%d seconds', $this->founderTokenTtlSeconds));
        $this->tokens->store($channelId, $target->id, $token, $expiresAt);
        $this->tokens->recordRequest($channelId);

        try {
            $this->mailSender->sendFounderChangeToken(
                $founderEmail,
                $command->channel->getName(),
                $targetNickname,
                $token,
                $command->serviceNickname,
                $command->locale,
            );
        } catch (Throwable) {
            return new TransferChannelFounderResult(TransferFounderOutcome::MailDeliveryFailed);
        }

        return new TransferChannelFounderResult(
            TransferFounderOutcome::TokenSent,
            emailHint: EmailHintMasker::mask($founderEmail),
        );
    }

    private function consumeToken(TransferChannelFounder $command): TransferChannelFounderResult
    {
        $newFounderNickId = $this->tokens->consume($command->channel->getId(), $command->token ?? '');
        if (null === $newFounderNickId) {
            return new TransferChannelFounderResult(TransferFounderOutcome::InvalidToken);
        }
        if ($command->channel->getFounderNickId() === $newFounderNickId) {
            return new TransferChannelFounderResult(TransferFounderOutcome::SameFounder);
        }
        if ($command->channel->getSuccessorNickId() === $newFounderNickId) {
            return new TransferChannelFounderResult(TransferFounderOutcome::TargetIsSuccessor);
        }

        return $this->transfer($command, $newFounderNickId, false);
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
