<?php

declare(strict_types=1);

namespace App\Application\NickServ\Security;

/**
 * Permission attributes used for NickServ authorization checks.
 * Used with Symfony Security isGranted($attribute, $subject).
 */
final class NickServPermission
{
    /** Command requires the sender to be identified as the account owner (same nick + +r). */
    public const string IDENTIFIED_OWNER = 'nickserv_identified_owner';

    /** Command requires the sender to be an IRC network operator (+o). */
    public const string NETWORK_OPER = 'network_oper';

    private function __construct()
    {
    }
}
