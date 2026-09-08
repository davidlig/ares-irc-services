<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerProviderInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandResult;
use App\OperServ\Application\Port\Out\RawDatabaseMutationFailure;
use App\OperServ\Application\Port\Out\RawDatabaseMutationHandler;
use App\OperServ\Application\Port\Out\RawDatabaseMutationResult;

use function is_string;

final readonly class UnrealUdbRawDatabaseMutationAdapter implements RawDatabaseMutationHandler
{
    public function __construct(private UdbRawCommandHandlerProviderInterface $provider) {}

    public function isAvailable(): bool
    {
        return null !== $this->provider->getActiveHandler();
    }

    public function insert(string $path, string $value): RawDatabaseMutationResult
    {
        $handler = $this->provider->getActiveHandler();

        return null === $handler
            ? RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::Rejected)
            : $this->map($handler->ins($path, $value));
    }

    public function delete(string $path): RawDatabaseMutationResult
    {
        $handler = $this->provider->getActiveHandler();

        return null === $handler
            ? RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::Rejected)
            : $this->map($handler->del($path));
    }

    private function map(UdbRawCommandResult $result): RawDatabaseMutationResult
    {
        if ($result->success) {
            return RawDatabaseMutationResult::success();
        }

        return match ($result->errorKey) {
            'raw.udb.invalid_block' => RawDatabaseMutationResult::failure(
                RawDatabaseMutationFailure::UnsupportedRecordType,
                recordType: $this->safeString($result->errorParams['%block%'] ?? null),
            ),
            'raw.udb.invalid_path' => RawDatabaseMutationResult::failure(
                RawDatabaseMutationFailure::InvalidRecordPath,
                recordPath: $this->safeString($result->errorParams['%path%'] ?? null),
            ),
            'raw.udb.invalid_value' => RawDatabaseMutationResult::failure(
                RawDatabaseMutationFailure::InvalidRecordValue,
                recordPath: $this->safeString($result->errorParams['%path%'] ?? null),
            ),
            default => RawDatabaseMutationResult::failure(RawDatabaseMutationFailure::Rejected),
        };
    }

    private function safeString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
