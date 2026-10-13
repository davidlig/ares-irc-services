<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageEmailVerification;

final readonly class ManageEmailVerificationResult
{
    public function __construct(
        public ManageEmailVerificationOutcome $outcome,
        public bool $required = true,
    ) {}

    public static function enabled(): self
    {
        return new self(ManageEmailVerificationOutcome::Enabled);
    }

    public static function disabled(): self
    {
        return new self(ManageEmailVerificationOutcome::Disabled, required: false);
    }

    public static function alreadyEnabled(): self
    {
        return new self(ManageEmailVerificationOutcome::AlreadyEnabled);
    }

    public static function alreadyDisabled(): self
    {
        return new self(ManageEmailVerificationOutcome::AlreadyDisabled, required: false);
    }

    public static function status(bool $required): self
    {
        return new self(ManageEmailVerificationOutcome::StatusReported, $required);
    }

    public static function unknownAction(): self
    {
        return new self(ManageEmailVerificationOutcome::UnknownAction);
    }
}
