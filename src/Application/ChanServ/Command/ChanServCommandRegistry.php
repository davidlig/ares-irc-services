<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command;

/**
 * Holds all registered ChanServ command modules (tag: chanserv.command).
 */
final readonly class ChanServCommandRegistry
{
    /** @var array<string, ChanServCommandInterface> keyed by uppercase name/alias */
    private array $map;

    /**
     * @param iterable<ChanServCommandInterface> $commands
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

    public function find(string $name): ?ChanServCommandInterface
    {
        return $this->map[strtoupper($name)] ?? null;
    }

    /** @return ChanServCommandInterface[] unique command instances */
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
