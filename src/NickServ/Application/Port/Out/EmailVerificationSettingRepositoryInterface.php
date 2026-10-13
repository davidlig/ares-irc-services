<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

/**
 * Reads and writes the network-wide e-mail verification policy.
 *
 * Implementations must treat "no setting stored" as verification required,
 * so the default policy holds without any seeding.
 */
interface EmailVerificationSettingRepositoryInterface
{
    public function isVerificationRequired(): bool;

    public function setVerificationRequired(bool $required): void;
}
