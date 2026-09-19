<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Security;

final readonly class MemoServPermission
{
    /** @return list<string> */
    public static function allIrcop(): array
    {
        return [];
    }

    private function __construct() {}
}
