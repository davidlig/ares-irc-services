<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\DatabaseMutationFailure;
use App\Irc\Application\Port\In\DatabaseMutationResult;
use App\Irc\Application\Port\In\ProtocolDatabaseMutation;
use App\OperServ\Application\Port\Out\RawDatabaseMutationFailure;
use App\OperServ\Application\Port\Out\RawDatabaseMutationHandler;
use App\OperServ\Application\Port\Out\RawDatabaseMutationResult;

final readonly class ProtocolRawDatabaseMutationHandler implements RawDatabaseMutationHandler
{
    public function __construct(private ProtocolDatabaseMutation $protocol) {}

    public function isAvailable(): bool
    {
        return $this->protocol->isAvailable();
    }

    public function insert(string $path, string $value): RawDatabaseMutationResult
    {
        return $this->map($this->protocol->insert($path, $value));
    }

    public function delete(string $path): RawDatabaseMutationResult
    {
        return $this->map($this->protocol->delete($path));
    }

    private function map(DatabaseMutationResult $result): RawDatabaseMutationResult
    {
        if ($result->success) {
            return RawDatabaseMutationResult::success();
        }

        return RawDatabaseMutationResult::failure(
            match ($result->failure) {
                DatabaseMutationFailure::UnsupportedRecordType => RawDatabaseMutationFailure::UnsupportedRecordType,
                DatabaseMutationFailure::InvalidPath => RawDatabaseMutationFailure::InvalidRecordPath,
                DatabaseMutationFailure::InvalidValue => RawDatabaseMutationFailure::InvalidRecordValue,
                default => RawDatabaseMutationFailure::Rejected,
            },
            recordType: $result->recordType,
            recordPath: $result->path,
        );
    }
}
