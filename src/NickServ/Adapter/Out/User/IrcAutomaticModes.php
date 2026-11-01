<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\Irc\Application\Port\In\AutomaticModeDelivery;
use App\Irc\Application\Port\In\AutomaticModeSupport;
use App\NickServ\Application\Port\Out\AutomaticModeActions;
use App\NickServ\Application\Port\Out\AutomaticModeCatalog;

final readonly class IrcAutomaticModes implements AutomaticModeCatalog, AutomaticModeActions
{
    public function __construct(private AutomaticModeSupport $support, private AutomaticModeDelivery $delivery) {}

    public function available(): ?array
    {
        return $this->support->getAutomaticModes();
    }

    public function usesNativeProfiles(): bool
    {
        return $this->delivery->usesNativeProfiles();
    }

    public function change(string $uid, string $delta): bool
    {
        return $this->delivery->changeUserModes($uid, $delta);
    }
}
