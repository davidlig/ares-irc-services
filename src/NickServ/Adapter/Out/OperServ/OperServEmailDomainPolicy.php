<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\OperServ;

use App\NickServ\Application\Port\Out\EmailDomainPolicy;
use App\OperServ\Application\Port\In\EmailDomainPolicy as OperServPolicy;

final readonly class OperServEmailDomainPolicy implements EmailDomainPolicy
{
    public function __construct(private OperServPolicy $policy) {}

    public function isBlocked(string $email): bool
    {
        return $this->policy->isBlocked($email);
    }
}
