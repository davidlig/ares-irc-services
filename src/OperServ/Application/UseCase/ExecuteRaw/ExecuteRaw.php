<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ExecuteRaw;

final readonly class ExecuteRaw
{
    /** @param list<string> $arguments */
    public function __construct(public array $arguments) {}
}
