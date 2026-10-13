<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Policy;

use App\NickServ\Application\Port\In\EmailVerificationPolicyInterface;
use App\OperServ\Application\Port\Out\EmailVerificationPolicyPortInterface;

/**
 * Reaches registration policy through NickServ's public port, never its internals.
 */
final readonly class EmailVerificationPolicyAdapter implements EmailVerificationPolicyPortInterface
{
    public function __construct(private EmailVerificationPolicyInterface $policy) {}

    public function isVerificationRequired(): bool
    {
        return $this->policy->isVerificationRequired();
    }

    public function setVerificationRequired(bool $required): void
    {
        $this->policy->setVerificationRequired($required);
    }
}
