<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Security;

use App\Application\Security\PermissionProviderInterface;

final readonly class ChanServIrcopPermission implements PermissionProviderInterface
{
    public const string DROP = 'CHANSERV_DROP';

    public const string SUSPEND = 'CHANSERV_SUSPEND';

    public const string UNSUSPEND = 'CHANSERV_UNSUSPEND';

    public const string CLOSE = 'CHANSERV_CLOSE';

    public const string UNCLOSE = 'CHANSERV_UNCLOSE';

    public function getServiceName(): string
    {
        return 'ChanServ';
    }

    public function getPermissions(): array
    {
        return [
            self::DROP,
            self::SUSPEND,
            self::UNSUSPEND,
            self::CLOSE,
            self::UNCLOSE,
        ];
    }
}
