<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Resend;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegistrationModeQuery;
use App\NickServ\Application\Port\Out\RescueTokenCodec;
use App\NickServ\Application\Port\Out\ResendMailSender;
use App\NickServ\Application\Port\Out\ResendThrottle;
use App\NickServ\Application\Port\Out\TemporaryRescueTokenStore;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use LogicException;
use OverflowException;
use Throwable;

use function sprintf;

use const FILTER_VALIDATE_EMAIL;

final readonly class ResendVerificationHandler implements ResendVerificationHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private ResendThrottle $throttle,
        private RescueTokenCodec $tokenGenerator,
        private TemporaryRescueTokenStore $temporaryTokens,
        private RegistrationModeQuery $registrationMode,
        private ResendMailSender $mailSender,
        private Clock $clock,
        private int $resendMinIntervalSeconds,
        private int $verificationTokenTtlSeconds = 3600,
    ) {}

    public function handle(ResendVerification $command): ResendVerificationResult
    {
        if (RegistrationMode::Token === $this->registrationMode->current()) {
            return ResendVerificationResult::disabled();
        }
        $account = $this->nickRepository->findByNick($command->nickname);

        if (null === $account || !$account->isPending()) {
            return ResendVerificationResult::noPending();
        }

        $now = $this->clock->now();
        if (null === $account->getExpiresAt() || $account->getExpiresAt() <= $now) {
            return ResendVerificationResult::noPending();
        }
        $remaining = $this->throttle->remainingCooldownSeconds(
            $command->nickname,
            $this->resendMinIntervalSeconds,
            $now,
        );

        if ($remaining > 0) {
            return ResendVerificationResult::throttled($remaining);
        }

        $recipientEmail = $account->getEmail();
        if (null === $recipientEmail || false === filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return ResendVerificationResult::noPending();
        }
        $token = $this->tokenGenerator->generate();
        $hash = $this->tokenGenerator->hash($token) ?? throw new LogicException('Generated rescue token is invalid.');
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->verificationTokenTtlSeconds));
        $this->temporaryTokens->prune($now);
        try {
            $this->temporaryTokens->replace($account->getId(), $hash, $expiresAt);
        } catch (OverflowException) {
            return ResendVerificationResult::mailDeliveryFailed();
        }

        $account->renewPendingRegistration($now, $expiresAt);
        $this->nickRepository->save($account);

        try {
            $this->mailSender->sendResend(
                $command->nickname,
                $recipientEmail,
                $token,
                $command->language,
            );
        } catch (Throwable) {
            return ResendVerificationResult::mailDeliveryFailed();
        }

        $this->throttle->recordResend($command->nickname, $now);

        return ResendVerificationResult::success($recipientEmail);
    }
}
