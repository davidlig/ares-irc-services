<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\ActiveConnectionHolderInterface;

use function implode;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function trim;

/** Executes the technical RAW command without leaking wire syntax into Application. */
final readonly class RawCommandExecutor
{
    public function __construct(
        private ActiveConnectionHolderInterface $connection,
        private ProtocolRawCommandInterceptorInterface $interceptor,
    ) {}

    /** @param list<string> $arguments */
    public function execute(array $arguments): RawCommandExecutionResult
    {
        $line = implode(' ', $arguments);
        if ('' === trim($line)) {
            return RawCommandExecutionResult::rejected(RawCommandExecutionOutcome::Empty);
        }
        if (510 < strlen($line)) {
            return RawCommandExecutionResult::rejected(RawCommandExecutionOutcome::TooLong);
        }
        if (!$this->connection->isConnected()) {
            return RawCommandExecutionResult::rejected(RawCommandExecutionOutcome::Disconnected);
        }

        $interception = $this->interceptor->intercept($arguments);
        if (null !== $interception) {
            return $interception;
        }

        $this->connection->writeLine($line);

        return RawCommandExecutionResult::executed($this->commandName($arguments), false);
    }

    /** @param list<string> $arguments */
    private function commandName(array $arguments): string
    {
        $first = $arguments[0] ?? '';

        return strtoupper(str_starts_with($first, ':') ? ($arguments[1] ?? 'UNKNOWN') : ($first ?: 'UNKNOWN'));
    }
}
