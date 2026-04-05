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

    /** IRCop permission to view the real IP/Host of a user. */
    public const string USERIP = 'nickserv.userip';

    /** IRCop permission to suspend a nickname. */
    public const string SUSPEND = 'nickserv.suspend';

    /** IRCop permission to force rename a connected user to a random nickname. */
    public const string RENAME = 'nickserv.rename';

    /** IRCop permission to drop a registered nickname. */
    public const string DROP = 'nickserv.drop';

    /** IRCop permission to forbid and unforbid nicknames. */
    public const string FORBID = 'nickserv.forbid';

    /** IRCop permission to forbid and allow vhost patterns. */
    public const string FORBIDVHOST = 'nickserv.forbidvhost';

    /** IRCop permission to modify another user's settings. */
    public const string SASET = 'nickserv.saset';

    private function __construct()
    {
    }
}
