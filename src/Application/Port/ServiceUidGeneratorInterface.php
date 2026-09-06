<?php

declare(strict_types=1);

namespace App\Application\Port;

interface ServiceUidGeneratorInterface
{
    public function generateUid(string $serviceKey): string;
}
