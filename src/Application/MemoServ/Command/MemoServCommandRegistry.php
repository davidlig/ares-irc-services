<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Command;

/**
 * Holds all registered MemoServ command modules (tag: memoserv.command).
 */
final readonly class MemoServCommandRegistry
{
    /** @var array<string, MemoServCommandInterface> keyed by uppercase name/alias */
    private array $map;

    /**
     * @param iterable<MemoServCommandInterface> $commands
     */
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

    public function find(string $name): ?MemoServCommandInterface
    {
        return $this->map[strtoupper($name)] ?? null;
    }

    /** @return MemoServCommandInterface[] unique command instances */
    public function all(): array
    {
        $seen = [];
        $unique = [];

        foreach ($this->map as $command) {
            $id = spl_object_id($command);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $unique[] = $command;
            }
        }

        return $unique;
    }
}
