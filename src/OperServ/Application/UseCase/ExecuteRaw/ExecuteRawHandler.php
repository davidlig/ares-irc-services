<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ExecuteRaw;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\RawDatabaseMutationFailure;
use App\OperServ\Application\Port\Out\RawDatabaseMutationHandler;
use App\OperServ\Application\Port\Out\RawDatabaseMutationResult;
use App\OperServ\Application\Port\Out\RawLineTransport;

use function array_slice;
use function count;
use function implode;
use function in_array;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

final readonly class ExecuteRawHandler
{
    public function __construct(
        private RawLineTransport $transport,
        private RawDatabaseMutationHandler $database,
        private CommandAuditRecorder $audit,
    ) {}

    public function handle(ExecuteRaw $command): ExecuteRawResult
    {
        $line = implode(' ', $command->arguments);
        if ('' === trim($line)) {
            return ExecuteRawResult::rejected(RawDatabaseExecutionOutcome::Empty);
        }
        if (510 < strlen($line)) {
            return ExecuteRawResult::rejected(RawDatabaseExecutionOutcome::TooLong);
        }
        if (!$this->transport->isConnected()) {
            return ExecuteRawResult::rejected(RawDatabaseExecutionOutcome::Disconnected);
        }

        $databaseResult = $this->handleDatabase($command);
        if (null !== $databaseResult) {
            return $databaseResult;
        }

        $this->transport->send($line);
        $operation = $this->commandName($command->arguments);
        $this->recordAudit($command, $operation, 'irc');

        return ExecuteRawResult::executed($operation);
    }

    private function handleDatabase(ExecuteRaw $command): ?ExecuteRawResult
    {
        $arguments = $command->arguments;
        if (!$this->database->isAvailable() || count($arguments) < 3 || 'DB' !== strtoupper($arguments[0])) {
            return null;
        }
        $subcommand = strtoupper($arguments[2]);
        if (!in_array($subcommand, ['INS', 'DEL', 'DRP', 'OPT'], true)) {
            return null;
        }
        if ('*' !== $arguments[1]) {
            return ExecuteRawResult::rejected(RawDatabaseExecutionOutcome::DatabaseTargetInvalid, recordPath: $arguments[1]);
        }
        if ('INS' === $subcommand) {
            if (count($arguments) < 5 || '' === trim($arguments[3])) {
                return ExecuteRawResult::rejected(RawDatabaseExecutionOutcome::DatabaseSyntaxInvalid);
            }
            $result = $this->database->insert($arguments[3], $this->decodeValue(implode(' ', array_slice($arguments, 4))));

            return $this->databaseResult($command, $result, 'DB INS');
        }
        if ('DEL' === $subcommand) {
            if (4 !== count($arguments) || '' === trim($arguments[3])) {
                return ExecuteRawResult::rejected(RawDatabaseExecutionOutcome::DatabaseSyntaxInvalid);
            }
            $result = $this->database->delete($arguments[3]);

            return $this->databaseResult($command, $result, 'DB DEL');
        }

        return ExecuteRawResult::rejected(RawDatabaseExecutionOutcome::DatabaseUnsupported);
    }

    private function databaseResult(ExecuteRaw $command, RawDatabaseMutationResult $result, string $operation): ExecuteRawResult
    {
        if ($result->successful) {
            $this->recordAudit($command, $operation, 'udb');

            return ExecuteRawResult::executed($operation, true);
        }

        return match ($result->failure) {
            RawDatabaseMutationFailure::UnsupportedRecordType => ExecuteRawResult::rejected(
                RawDatabaseExecutionOutcome::DatabaseRecordTypeInvalid,
                recordType: $result->recordType,
            ),
            RawDatabaseMutationFailure::InvalidRecordPath => ExecuteRawResult::rejected(
                RawDatabaseExecutionOutcome::DatabasePathInvalid,
                recordPath: $result->recordPath,
            ),
            RawDatabaseMutationFailure::InvalidRecordValue => ExecuteRawResult::rejected(
                RawDatabaseExecutionOutcome::DatabaseValueInvalid,
                recordPath: $result->recordPath,
            ),
            RawDatabaseMutationFailure::Rejected, null => ExecuteRawResult::rejected(RawDatabaseExecutionOutcome::DatabaseFailed),
        };
    }

    private function recordAudit(ExecuteRaw $command, string $operation, string $transport): void
    {
        $this->audit->record(new CommandAuditRecord(
            category: CommandAuditCategory::OperatorAction,
            service: 'OperServ',
            actor: $command->actorNickname,
            operation: 'RAW',
            occurredAt: $command->occurredAt,
            target: $operation,
            permission: 'operserv.raw',
            metadata: ['transport' => $transport],
        ));
    }

    /** @param list<string> $arguments */
    private function commandName(array $arguments): string
    {
        $first = $arguments[0] ?? '';

        return strtoupper(str_starts_with($first, ':') ? ($arguments[1] ?? 'UNKNOWN') : ($first ?: 'UNKNOWN'));
    }

    private function decodeValue(string $value): string
    {
        if (str_starts_with($value, ':')) {
            $value = substr($value, 1);
        }

        return strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')
            ? substr($value, 1, -1)
            : $value;
    }
}
