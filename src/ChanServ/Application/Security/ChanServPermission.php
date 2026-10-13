<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Security;

final readonly class ChanServPermission
{
    public const string DROP = 'chanserv.drop';

    public const string DROP_FORCE = 'chanserv.drop.force';

    public const string RESTORE = 'chanserv.restore';

    public const string SUSPEND = 'chanserv.suspend';

    public const string FORBID = 'chanserv.forbid';

    public const string NOEXPIRE = 'chanserv.noexpire';

    public const string IRCOPONLY = 'chanserv.ircoponly';

    public const string LEVEL_FOUNDER = 'chanserv.level_founder';

    public const string HISTORY = 'chanserv.history';

    public const string CLEARACCESS = 'chanserv.clearaccess';

    public const string CLEARUSERS = 'chanserv.clearusers';

    public const string LIST = 'chanserv.list';

    /** IRCop permission to register a channel on behalf of another user, without being in it. */
    public const string SAREGISTER = 'chanserv.saregister';

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
            self::SAREGISTER,
        ];
    }

    private function __construct() {}
}
