<?php

declare(strict_types=1);

namespace App\OperServ\Application;

use App\Shared\Domain\IrcIdentityFold;

use function array_filter;
use function array_map;
use function array_values;
use function explode;
use function strtolower;

final readonly class RootUserRegistry
{
    /** @var array<string, string> ASCII-lowered network nicknames keyed by the service identity fold */
    private array $rootNicksLower;

    public function __construct(string $rootUsers)
    {
        $rootNicks = [];
        foreach (array_filter(array_map('trim', explode(',', $rootUsers))) as $nick) {
            $rootNicks[IrcIdentityFold::fold($nick)] ??= strtolower($nick);
        }
        $this->rootNicksLower = $rootNicks;
    }

    public function isRoot(string $nick): bool
    {
        return isset($this->rootNicksLower[IrcIdentityFold::fold($nick)]);
    }

    /** @return list<string> */
    public function getRootNicks(): array
    {
        return array_values($this->rootNicksLower);
    }
}
