<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol;

/** Public technical boundary for protocol-owned handling of privileged raw commands. */
interface RawCommandInterceptorInterface
{
    /** @param list<string> $arguments */
    public function intercept(array $arguments): RawCommandInterception;
}
