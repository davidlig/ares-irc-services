<?php

declare(strict_types=1);

namespace App\CRegServ\Application\UseCase\RequestChannelRegistration;

use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Application\Port\Out\RegisteredChannelLookupPort;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;

use function ceil;
use function str_starts_with;

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

        if ($this->channelLookup->existsByChannelName($command->channelName)) {
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

        $this->requestRepository->save(ChannelRegistrationRequest::request(
            requestedAt: $command->occurredAt,
            channelName: $command->channelName,
            requesterAccountId: $command->requesterAccountId,
            requesterNick: $command->requesterNickname,
            description: $command->description,
        ));

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
