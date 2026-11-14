<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Security;

use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use App\Shared\Domain\IrcIdentityFold;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function strtolower;

final readonly class ConfiguredRootIdentityRegistry implements RootIdentityRegistry
{
    /** @var array<string, string> ASCII-lowered network nicknames keyed by the service identity fold */
    private array $nicknames;

    public function __construct(string $rootUsers)
    {
        $nicknames = [];
        foreach (array_filter(array_map('trim', explode(',', $rootUsers))) as $nickname) {
            $nicknames[IrcIdentityFold::fold($nickname)] ??= strtolower($nickname);
        }
        $this->nicknames = $nicknames;
    }

    public function contains(string $nickname): bool
    {
        return isset($this->nicknames[IrcIdentityFold::fold($nickname)]);
    }

    public function allNicknames(): array
    {
        return array_values($this->nicknames);
    }
}
