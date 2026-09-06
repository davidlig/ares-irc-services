<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

interface ServiceUidGeneratorInterface
{
    public function generateUid(string $serviceKey): string;
}
