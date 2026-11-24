<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

final readonly class RescueTokenValidation
{
    public function __construct(public int $accountId, #[\SensitiveParameter] public string $token, public string $clientKey) {}
}
