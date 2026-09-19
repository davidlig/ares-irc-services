<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Security;

use App\OperServ\Application\Port\Out\RootIdentityRegistry;

final readonly class ConfiguredRootIdentityRegistry implements RootIdentityRegistry
{
    /** @var array<string, true> */
    private array $nicknames;

    public function __construct(string $rootUsers)
    {
        $values = array_filter(array_map('trim', explode(',', $rootUsers)));
        $this->nicknames = array_fill_keys(array_map('strtolower', $values), true);
    }

    public function contains(string $nickname): bool
    {
        return isset($this->nicknames[strtolower($nickname)]);
    }

    public function allNicknames(): array
    {
        return array_keys($this->nicknames);
    }
}
