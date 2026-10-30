<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Security;

/**
 * Permission attributes used for ChanServ authorization checks.
 * Used with Symfony Security isGranted($attribute, $subject).
 */
final readonly class ChanServPermission
{
    /** IRCop permission to drop a registered channel. */
    public const string DROP = 'chanserv.drop';

    /** IRCop permission to force permanent deletion of a recoverable channel drop. */
    public const string DROP_FORCE = 'chanserv.drop.force';

    /** IRCop permission to restore a channel in recoverable deletion grace. */
    public const string RESTORE = 'chanserv.restore';

    /** IRCop permission to suspend a registered channel. */
    public const string SUSPEND = 'chanserv.suspend';

    /** IRCop permission to forbid and unforbid channels. */
    public const string FORBID = 'chanserv.forbid';

    /** IRCop permission to protect a channel from expiration. */
    public const string NOEXPIRE = 'chanserv.noexpire';

    /** IRCop permission to restrict channel registration or usage to IRCops. */
    public const string IRCOPONLY = 'chanserv.ircoponly';

    /** IRCop permission to act with Founder-level authority on any channel. */
    public const string LEVEL_FOUNDER = 'chanserv.level_founder';

    /** IRCop permission to view and manage channel action history. */
    public const string HISTORY = 'chanserv.history';

    /** IRCop permission to clear channel access lists. */
    public const string CLEARACCESS = 'chanserv.clearaccess';

    /** IRCop permission to kick users from a channel. */
    public const string CLEARUSERS = 'chanserv.clearusers';

    /** IRCop permission to list registered channels and their properties. */
    public const string LIST = 'chanserv.list';

    /** IRCop permission to change the channel registration policy. */
    public const string REGMODE = 'chanserv.regmode';

    /** IRCop permission to review, approve, or reject channel registrations. */
    public const string REGISTRATION_REVIEW = 'chanserv.registration_review';

    /** IRCop permission to administratively register channels. */
    public const string SAREGISTER = 'chanserv.saregister';

    private function __construct() {}

    /**
     * @return list<string>
     */
    public static function allIrcop(): array
    {
        return [
            self::CLEARACCESS,
            self::DROP,
            self::DROP_FORCE,
            self::RESTORE,
            self::SUSPEND,
            self::FORBID,
            self::NOEXPIRE,
            self::IRCOPONLY,
            self::LEVEL_FOUNDER,
            self::HISTORY,
            self::CLEARUSERS,
            self::LIST,
            self::REGMODE,
            self::REGISTRATION_REVIEW,
            self::SAREGISTER,
        ];
    }
}
