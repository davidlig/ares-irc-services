<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Info;

use App\NickServ\Application\Port\Out\NickAssociatedChannelsPort;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\VhostDisplayResolver;

use function strcasecmp;

final readonly class InfoNickHandler implements InfoNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickAssociatedChannelsPort $channelsPort,
        private VhostDisplayResolver $vhostDisplayResolver,
        private int $dropGraceDays = 7,
    ) {}

    public function handle(InfoNick $command): InfoNickResult
    {
        $account = $this->nickRepository->findByNick($command->targetNick);

        if (null === $account || $account->isPending()) {
            return InfoNickResult::notRegistered($command->targetNick);
        }

        if ($account->isForbidden()) {
            return InfoNickResult::forbidden($account->getNickname(), $account->getReason());
        }

        if ($account->isPendingDeletion()) {
            return InfoNickResult::pendingDeletion(
                $account->getNickname(),
                $account->getPendingDeletionAt(),
                $account->getPendingDeletionExpiresAt($this->dropGraceDays),
            );
        }

        $isOwner = null !== $command->senderNick && 0 === strcasecmp($command->senderNick, $account->getNickname());
        if ($account->isPrivate() && !$isOwner) {
            return InfoNickResult::private($account->getNickname());
        }

        $isOwnerIdentified = $isOwner && $command->senderIsIdentified;
        $canSeeConnectionInfo = $isOwnerIdentified || $command->senderIsOper;

        $lastSeenOnline = $command->targetIsOnlineAndIdentified;
        $lastSeenAt = $lastSeenOnline ? null : $account->getLastSeenAt();

        $lastConnectIp = null;
        $lastConnectHost = null;
        if ($canSeeConnectionInfo && (null !== $account->getLastConnectIp() || null !== $account->getLastConnectHost())) {
            $lastConnectIp = $account->getLastConnectIp() ?? '*';
            $lastConnectHost = $account->getLastConnectHost() ?? '*';
        }

        $email = $isOwnerIdentified ? $account->getEmail() : null;
        $displayVhost = $this->vhostDisplayResolver->getDisplayVhost($account->getVhost());

        $channels = [];
        if ($isOwnerIdentified) {
            $channels = $this->channelsPort->findChannelsForNick($account->getId());
        }

        return InfoNickResult::visible(
            nickname: $account->getNickname(),
            status: $account->getStatus(),
            suspendedReason: $account->isSuspended() ? $account->getReason() : null,
            suspendedUntil: $account->isSuspended() ? $account->getSuspendedUntil() : null,
            registeredAt: $account->getRegisteredAt(),
            lastSeenOnline: $lastSeenOnline,
            lastSeenAt: $lastSeenAt,
            lastQuitMessage: $account->getLastQuitMessage(),
            lastConnectIp: $lastConnectIp,
            lastConnectHost: $lastConnectHost,
            language: $account->getLanguage(),
            email: $email,
            displayVhost: $displayVhost,
            isNoExpire: $account->isNoExpire(),
            isOwnerIdentified: $isOwnerIdentified,
            channels: $channels,
        );
    }
}
