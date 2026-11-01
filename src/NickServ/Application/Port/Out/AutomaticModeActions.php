<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

interface AutomaticModeActions
{
    public function usesNativeProfiles(): bool;

    public function change(string $uid, string $delta): bool;
}
