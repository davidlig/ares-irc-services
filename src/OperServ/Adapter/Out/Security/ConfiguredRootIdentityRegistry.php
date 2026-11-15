<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Security;

use App\Irc\Application\Port\In\NetworkIdentityEquivalence;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;

use function array_any;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function strtolower;

final readonly class ConfiguredRootIdentityRegistry implements RootIdentityRegistry
{
    /** @var list<string> every configured spelling, ASCII-lowered; one network may tell them apart */
    private array $nicknames;

    public function __construct(string $rootUsers, private NetworkIdentityEquivalence $equivalence)
    {
        $this->nicknames = array_values(array_unique(array_map(
            strtolower(...),
            array_filter(array_map('trim', explode(',', $rootUsers))),
        )));
    }

    public function contains(string $nickname): bool
    {
        return array_any($this->nicknames, fn (string $root): bool => $this->equivalence->equivalent($root, $nickname));
    }

    public function allNicknames(): array
    {
        return $this->nicknames;
    }
}
