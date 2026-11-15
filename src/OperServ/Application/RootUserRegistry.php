<?php

declare(strict_types=1);

namespace App\OperServ\Application;

use App\OperServ\Application\Port\Out\NetworkNickEquivalence;

use function array_any;
use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function strtolower;

final readonly class RootUserRegistry
{
    /** @var list<string> every configured spelling, ASCII-lowered; one network may tell them apart */
    private array $rootNicksLower;

    public function __construct(string $rootUsers, private NetworkNickEquivalence $equivalence)
    {
        $this->rootNicksLower = array_values(array_unique(array_map(
            strtolower(...),
            array_filter(array_map('trim', explode(',', $rootUsers))),
        )));
    }

    public function isRoot(string $nick): bool
    {
        return array_any($this->rootNicksLower, fn (string $root): bool => $this->equivalence->equivalent($root, $nick));
    }

    /** @return list<string> */
    public function getRootNicks(): array
    {
        return $this->rootNicksLower;
    }
}
