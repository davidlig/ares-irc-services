<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Verify;

use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\IdentifiedSessionTracker;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegistrationEventPublisher;
use App\NickServ\Application\Port\Out\VerificationTokenConsumer;
use App\NickServ\Application\PublishedEvent\NickPasswordHashAvailable;

final readonly class VerifyNickHandler implements VerifyNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private VerificationTokenConsumer $tokenConsumer,
        private IdentifiedSessionTracker $identifiedRegistry,
        private Clock $clock,
        private NicknameAuthenticationModeQuery $authenticationMode,
        private RegistrationEventPublisher $eventPublisher,
    ) {}

    public function handle(VerifyNick $command): VerifyNickResult
    {
        $account = $this->nickRepository->findByNick($command->nickname);

        if (null === $account || !$account->isPending()) {
            return VerifyNickResult::noPending();
        }

        if (!$this->tokenConsumer->consume($command->nickname, $command->token, $this->clock->now())) {
            return VerifyNickResult::invalidToken();
        }

        $account->activate();
        $this->nickRepository->save($account);
        $this->eventPublisher->publish(new NickPasswordHashAvailable(
            nickId: $account->getId(),
            nickname: $account->getNickname(),
            passwordHash: $account->getPasswordHash(),
        ));

        if (NicknameAuthenticationMode::NativeNick === $this->authenticationMode->current()) {
            return VerifyNickResult::successNativeAuthenticationRequired($account->getNickname());
        }

        $this->identifiedRegistry->register($command->senderUid, $account->getNickname());

        return VerifyNickResult::success($account->getNickname());
    }
}
