<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

/**
 * Public boundary for the network-wide e-mail verification policy.
 *
 * Other contexts reach registration policy through this port, never through
 * NickServ's internal repositories.
 */
interface EmailVerificationPolicyInterface
{
    public function isVerificationRequired(): bool;

    public function setVerificationRequired(bool $required): void;
}
