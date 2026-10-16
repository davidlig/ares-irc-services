<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageEmailVerification;

use App\OperServ\Application\Port\Out\EmailVerificationPolicyPortInterface;

final readonly class ManageEmailVerificationHandler implements ManageEmailVerificationHandlerInterface
{
    public function __construct(private EmailVerificationPolicyPortInterface $policy) {}

    public function handle(ManageEmailVerification $command): ManageEmailVerificationResult
    {
        $required = $this->policy->isVerificationRequired();

        return match ($command->action) {
            EmailVerificationAction::Status => ManageEmailVerificationResult::status($required),
            EmailVerificationAction::Enable => $required
                ? ManageEmailVerificationResult::alreadyEnabled()
                : $this->apply(true),
            EmailVerificationAction::Disable => $required
                ? $this->apply(false)
                : ManageEmailVerificationResult::alreadyDisabled(),
            EmailVerificationAction::Unknown => ManageEmailVerificationResult::unknownAction(),
        };
    }

    private function apply(bool $required): ManageEmailVerificationResult
    {
        $this->policy->setVerificationRequired($required);

        return $required
            ? ManageEmailVerificationResult::enabled()
            : ManageEmailVerificationResult::disabled();
    }
}
