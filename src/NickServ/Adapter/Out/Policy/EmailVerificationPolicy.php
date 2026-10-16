<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Policy;

use App\NickServ\Application\Port\In\EmailVerificationPolicyInterface;
use App\NickServ\Application\Port\Out\EmailVerificationSettingRepositoryInterface;

final readonly class EmailVerificationPolicy implements EmailVerificationPolicyInterface
{
    public function __construct(private EmailVerificationSettingRepositoryInterface $settings) {}

    public function isVerificationRequired(): bool
    {
        return $this->settings->isVerificationRequired();
    }

    public function setVerificationRequired(bool $required): void
    {
        $this->settings->setVerificationRequired($required);
    }
}
