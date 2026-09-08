<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb\Session;

interface UdbStoreInitializationListener
{
    public function onStoreInitialized(): void;
}
