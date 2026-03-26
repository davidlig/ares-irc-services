<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Security;

use App\Application\Security\PermissionProviderInterface;

final readonly class MemoServIrcopPermission implements PermissionProviderInterface
{
    public const string SEND = 'MEMOSERV_SEND';

    public const string DISABLE = 'MEMOSERV_DISABLE';

    public const string ENABLE = 'MEMOSERV_ENABLE';

    public const string READ = 'MEMOSERV_READ';

    public const string DELETE = 'MEMOSERV_DELETE';

    public function getServiceName(): string
    {
        return 'MemoServ';
    }

    public function getPermissions(): array
    {
        return [
            self::SEND,
            self::DISABLE,
            self::ENABLE,
            self::READ,
            self::DELETE,
        ];
    }
}
