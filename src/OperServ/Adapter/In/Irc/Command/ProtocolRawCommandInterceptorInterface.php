<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

/** Adapter-local boundary implemented by Bootstrap to isolate IRC protocol internals. */
interface ProtocolRawCommandInterceptorInterface
{
    /**
     * @param list<string> $arguments
     *
     * @return RawCommandExecutionResult|null null when the selected protocol does not own the command
     */
    public function intercept(array $arguments): ?RawCommandExecutionResult;
}
