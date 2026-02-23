<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command;

/**
 * Holds all registered NickServ command modules.
 * Commands are injected via tagged services (tag: nickserv.command).
 */
final readonly class NickServCommandRegistry
{
    /** @var array<string, NickServCommandInterface> keyed by uppercase name/alias */
    private array $map;

    /**
     * @param iterable<NickServCommandInterface> $commands
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

    public function find(string $name): ?NickServCommandInterface
    {
        return $this->map[strtoupper($name)] ?? null;
    }

    /** @return NickServCommandInterface[] unique command instances (one per handler, aliases deduplicated) */
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
