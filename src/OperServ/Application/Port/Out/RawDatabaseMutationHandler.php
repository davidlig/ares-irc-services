<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\Out;

interface RawDatabaseMutationHandler
{
    public function isAvailable(): bool;

    public function insert(string $path, string $value): RawDatabaseMutationResult;

    public function delete(string $path): RawDatabaseMutationResult;
}
