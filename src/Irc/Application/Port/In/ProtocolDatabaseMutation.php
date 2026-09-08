<?php

declare(strict_types=1);

namespace App\Irc\Application\Port\In;

interface ProtocolDatabaseMutation
{
    public function isAvailable(): bool;

    public function insert(string $path, string $value): DatabaseMutationResult;

    public function delete(string $path): DatabaseMutationResult;
}
