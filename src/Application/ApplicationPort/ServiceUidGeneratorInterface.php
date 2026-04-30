<?php

declare(strict_types=1);

namespace App\Application\ApplicationPort;

interface ServiceUidGeneratorInterface
{
    public function generateUid(string $serviceKey): string;
}
