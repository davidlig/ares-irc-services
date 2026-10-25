<?php

declare(strict_types=1);

namespace App\OperServ\Domain\ValueObject;

use InvalidArgumentException;

final readonly class EmailDomain
{
    public string $value;

    public function __construct(string $domain)
    {
        $domain = strtolower(trim($domain));
        if (253 < strlen($domain)
            || 1 !== preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/D', $domain)
            || false !== filter_var($domain, FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('Invalid email domain.');
        }
        $this->value = $domain;
    }
}
