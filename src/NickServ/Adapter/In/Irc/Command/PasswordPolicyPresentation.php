<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Service\PasswordPolicy;
use App\Shared\Adapter\In\Irc\Presentation\Role;

final class PasswordPolicyPresentation
{
    public static function reject(NickServContext $context, ?PasswordPolicy $policy): bool
    {
        $policy ??= new PasswordPolicy();
        $context->reply('password_policy.rejected.' . $policy->complexity, [
            'minimum' => $policy->minimumLength,
        ], Role::Error);

        return false;
    }
}
