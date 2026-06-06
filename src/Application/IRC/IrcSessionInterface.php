<?php

declare(strict_types=1);

namespace App\Application\IRC;

interface IrcSessionInterface
{
    public function run(): void;

    public function disconnect(?string $reason = null): void;

    public function getProtocolName(): string;
}
