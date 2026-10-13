<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

/**
 * OperServ-owned view of registration e-mail verification policy.
 *
 * Implemented by an OperServ adapter that delegates to NickServ's public port.
 */
interface EmailVerificationPolicyPortInterface
{
    public function isVerificationRequired(): bool;

    public function setVerificationRequired(bool $required): void;
}
