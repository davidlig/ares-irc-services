<?php

declare(strict_types=1);

namespace App\Application\NickServ\Security;

/**
 * Permission attributes used for NickServ authorization checks.
 * Used with Symfony Security isGranted($attribute, $subject).
 */
final readonly class NickServPermission
{
    /** Command requires the sender to be identified as the account owner (same nick + +r). */
    public const string IDENTIFIED_OWNER = 'nickserv_identified_owner';

    private function __construct()
    {
    }
}
