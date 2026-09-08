<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerProviderInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandResult;
use App\Irc\Application\Port\In\DatabaseMutationFailure;
use App\Irc\Application\Port\In\DatabaseMutationResult;
use App\Irc\Application\Port\In\ProtocolDatabaseMutation;

use function is_string;

final readonly class ActiveUdbDatabaseMutation implements ProtocolDatabaseMutation
{
    public function __construct(private UdbRawCommandHandlerProviderInterface $provider) {}

    public function isAvailable(): bool
    {
        return null !== $this->provider->getActiveHandler();
    }

    public function insert(string $path, string $value): DatabaseMutationResult
    {
        $handler = $this->provider->getActiveHandler();

        return null === $handler
            ? DatabaseMutationResult::failure(DatabaseMutationFailure::Rejected)
            : $this->map($handler->ins($path, $value));
    }

    public function delete(string $path): DatabaseMutationResult
    {
        $handler = $this->provider->getActiveHandler();

        return null === $handler
            ? DatabaseMutationResult::failure(DatabaseMutationFailure::Rejected)
            : $this->map($handler->del($path));
    }

    private function map(UdbRawCommandResult $result): DatabaseMutationResult
    {
        if ($result->success) {
            return DatabaseMutationResult::success();
        }

        return match ($result->errorKey) {
            'raw.udb.invalid_block' => DatabaseMutationResult::failure(
                DatabaseMutationFailure::UnsupportedRecordType,
                recordType: $this->safeString($result->errorParams['%block%'] ?? null),
            ),
            'raw.udb.invalid_path' => DatabaseMutationResult::failure(
                DatabaseMutationFailure::InvalidPath,
                path: $this->safeString($result->errorParams['%path%'] ?? null),
            ),
            'raw.udb.invalid_value' => DatabaseMutationResult::failure(
                DatabaseMutationFailure::InvalidValue,
                path: $this->safeString($result->errorParams['%path%'] ?? null),
            ),
            default => DatabaseMutationResult::failure(DatabaseMutationFailure::Rejected),
        };
    }

    private function safeString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
