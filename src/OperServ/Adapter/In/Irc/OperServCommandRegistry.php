<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc;

use function spl_object_id;
use function strtoupper;

final readonly class OperServCommandRegistry
{
    /** @var array<string, OperServCommandInterface> */
    private array $map;

    /** @param iterable<OperServCommandInterface> $commands */
    public function __construct(iterable $commands)
    {
        $map = [];
        foreach ($commands as $command) {
            $map[strtoupper($command->getName())] = $command;
            foreach ($command->getAliases() as $alias) {
                $map[strtoupper($alias)] = $command;
            }
        }

        $this->map = $map;
    }

    public function find(string $name): ?OperServCommandInterface
    {
        return $this->map[strtoupper($name)] ?? null;
    }

    /** @return list<OperServCommandInterface> */
    public function all(): array
    {
        $seen = [];
        $commands = [];
        foreach ($this->map as $command) {
            $id = spl_object_id($command);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $commands[] = $command;
            }
        }

        return $commands;
    }
}
