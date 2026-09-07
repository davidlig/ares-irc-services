<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface NickProtectionExemption
{
    public function isRootNickname(string $nickname): bool;

    public function isServiceNickname(string $nickname): bool;

    public function isIrcopNickId(int $nickId): bool;
}
