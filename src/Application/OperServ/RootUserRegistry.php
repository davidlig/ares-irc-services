<?php

declare(strict_types=1);

namespace App\Application\OperServ;

final readonly class RootUserRegistry
{
    /** @var array<string, true> */
    private array $rootNicksLower;

    public function __construct(string $rootUsers)
    {
        $nicks = array_filter(array_map('trim', explode(',', $rootUsers)));
        $this->rootNicksLower = array_fill_keys(
            array_map('strtolower', $nicks),
            true
        );
    }

    public function isRoot(string $nick): bool
    {
        return isset($this->rootNicksLower[strtolower($nick)]);
    }

    public function getRootNicks(): array
    {
        return array_keys($this->rootNicksLower);
    }
}
