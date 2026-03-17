<?php

declare(strict_types=1);

namespace App\Application\OperServ\Security;

final class OperServPermission
{
    public const string ADMIN_ADD = 'operserv.admin.add';

    public const string ADMIN_DEL = 'operserv.admin.del';

    public const string ADMIN_LIST = 'operserv.admin.list';

    public const string ROLE_MANAGE = 'operserv.role.manage';

    public const string PERMISSION_MANAGE = 'operserv.permission.manage';

    public const string KILL_LOCAL = 'operserv.kill.local';

    public const string KILL_GLOBAL = 'operserv.kill.global';

    public const string KLINE_ADD = 'operserv.kline.add';

    public const string KLINE_DEL = 'operserv.kline.del';

    public const string KLINE_LIST = 'operserv.kline.list';

    public const string GLINE_ADD = 'operserv.gline.add';

    public const string GLINE_DEL = 'operserv.gline.del';

    public const string GLINE_LIST = 'operserv.gline.list';

    public const string USERINFO = 'operserv.userinfo';

    public const string CHANNELINFO = 'operserv.channelinfo';

    public const string NETWORK_VIEW = 'operserv.network.view';

    private function __construct()
    {
    }
}
