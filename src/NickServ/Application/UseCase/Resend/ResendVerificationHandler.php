<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\Resend;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Port\Out\RegistrationVerificationStore;
use App\NickServ\Application\Port\Out\ResendMailSender;
use App\NickServ\Application\Port\Out\ResendThrottle;
use App\NickServ\Application\Port\Out\VerificationTokenGenerator;
use Throwable;

use function sprintf;

final readonly class ResendVerificationHandler implements ResendVerificationHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private ResendThrottle $throttle,
        private VerificationTokenGenerator $tokenGenerator,
        private RegistrationVerificationStore $verificationStore,
        private ResendMailSender $mailSender,
        private Clock $clock,
        private int $resendMinIntervalSeconds,
        private int $tokenTtlSeconds = 3600,
    ) {}

    public function handle(ResendVerification $command): ResendVerificationResult
    {
        $account = $this->nickRepository->findByNick($command->nickname);

        if (null === $account || !$account->isPending()) {
            return ResendVerificationResult::noPending();
        }

        $now = $this->clock->now();
        $remaining = $this->throttle->remainingCooldownSeconds(
            $command->nickname,
            $this->resendMinIntervalSeconds,
            $now,
        );

        if ($remaining > 0) {
            return ResendVerificationResult::throttled($remaining);
        }

        $token = $this->tokenGenerator->generate();
        $expiresAt = $now->modify(sprintf('+%d seconds', $this->tokenTtlSeconds));

        $this->verificationStore->store($command->nickname, $token, $expiresAt);

        $recipientEmail = $account->getEmail() ?? '';
        if ('' !== $recipientEmail) {
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
        }

        $this->throttle->recordResend($command->nickname, $now);

        return ResendVerificationResult::success($recipientEmail);
    }
}
