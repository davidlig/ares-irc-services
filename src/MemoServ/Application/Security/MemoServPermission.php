<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Security;

final readonly class MemoServPermission
{
    private function __construct() {}

    /** @return list<string> */
    public static function allIrcop(): array
    {
        return [];
    }
}
