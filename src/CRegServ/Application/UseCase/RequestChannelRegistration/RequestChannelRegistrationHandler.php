<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\RequestChannelRegistration;

use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Application\Port\Out\RegisteredChannelLookupPort;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\Exception\ChannelRegistrationConflictException;

use function ceil;
use function str_starts_with;
use function strlen;

/**
 * Files a channel registration request and leaves it waiting for a services oper.
 *
 * Nothing is written to UDB here: the C block is only produced once a request is approved.
 * The checks mirror pulsar's do_registra (modules/cregserv/main.c:440-531), which rejects a
 * channel already held by ChanServ, a channel already queued or denied, and a requester who
 * asked again before the throttle interval elapsed. Pulsar keeps that interval in the
 * `CRSSigRegistro` config (1d in data/modules.conf) and exempts services operators.
 */
final readonly class RequestChannelRegistrationHandler implements RequestChannelRegistrationHandlerInterface
{
    /** Size of the channel and channel_lower columns. */
    private const MAX_CHANNEL_LENGTH = 64;

    /** Size of the description column. */
    private const MAX_DESCRIPTION_LENGTH = 255;

    public function __construct(
        private ChannelRegistrationRequestRepositoryInterface $requestRepository,
        private RegisteredChannelLookupPort $channelLookup,
        private int $minIntervalSeconds = 86400,
    ) {}

    public function handle(RequestChannelRegistration $command): RequestChannelRegistrationResult
    {
        if (!str_starts_with($command->channelName, '#')) {
            return RequestChannelRegistrationResult::invalidChannel();
        }

        // The columns hold 64 and 255 characters and IRC hands the input over unbounded, so a
        // longer value would either be rejected by the database at write time or silently cut.
        if (strlen($command->channelName) > self::MAX_CHANNEL_LENGTH) {
            return RequestChannelRegistrationResult::channelTooLong();
        }

        if (strlen($command->description) > self::MAX_DESCRIPTION_LENGTH) {
            return RequestChannelRegistrationResult::descriptionTooLong();
        }

        if ($this->channelLookup->existsByChannelName($command->channelName)) {
            if ($this->channelLookup->isPendingDeletion($command->channelName)) {
                return RequestChannelRegistrationResult::pendingDeletion();
            }

            return RequestChannelRegistrationResult::alreadyRegistered();
        }

        $existing = $this->requestRepository->findByChannel($command->channelName);
        if (null !== $existing) {
            if ($existing->isApproved()) {
                return RequestChannelRegistrationResult::alreadyRegistered();
            }

            if ($existing->isDenied()) {
                return RequestChannelRegistrationResult::requestDenied();
            }

            return RequestChannelRegistrationResult::duplicateRequest();
        }

        if (!$command->actorIrcOperator) {
            $remainingSeconds = $this->getRemainingCooldownSeconds($command);
            if ($remainingSeconds > 0) {
                return RequestChannelRegistrationResult::throttled((int) ceil($remainingSeconds / 60));
            }
        }

        try {
            $this->requestRepository->save(ChannelRegistrationRequest::request(
                requestedAt: $command->occurredAt,
                channelName: $command->channelName,
                requesterAccountId: $command->requesterAccountId,
                requesterNick: $command->requesterNickname,
                description: $command->description,
            ));
        } catch (ChannelRegistrationConflictException) {
            // The duplicate guard above is read-then-write, so a concurrent request for the
            // same channel can win the unique index first. That is a duplicate, not a crash.
            return RequestChannelRegistrationResult::duplicateRequest();
        }

        return RequestChannelRegistrationResult::requested();
    }

    private function getRemainingCooldownSeconds(RequestChannelRegistration $command): int
    {
        $lastRequest = $this->requestRepository->findLastRequestByAccount($command->requesterAccountId);
        if (null === $lastRequest) {
            return 0;
        }

        return ($lastRequest->getCreatedAt()->getTimestamp() + $this->minIntervalSeconds)
            - $command->occurredAt->getTimestamp();
    }
}
