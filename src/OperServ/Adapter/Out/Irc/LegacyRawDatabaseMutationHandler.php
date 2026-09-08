<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\Application\Port\UdbRawCommandHandlerProviderInterface;
use App\Application\Port\UdbRawCommandResult;
use App\OperServ\Application\Port\Out\RawDatabaseMutationHandler;
use App\OperServ\Application\Port\Out\RawDatabaseMutationResult;
use App\Shared\Application\Audit\SafeAuditMetadata;

final readonly class LegacyRawDatabaseMutationHandler implements RawDatabaseMutationHandler
{
    public function __construct(private UdbRawCommandHandlerProviderInterface $provider) {}

    public function isAvailable(): bool
    {
        return null !== $this->provider->getActiveHandler();
    }

    public function insert(string $path, string $value): RawDatabaseMutationResult
    {
        $handler = $this->provider->getActiveHandler();

        return null === $handler ? RawDatabaseMutationResult::failure('raw.udb.error') : $this->map($handler->ins($path, $value));
    }

    public function delete(string $path): RawDatabaseMutationResult
    {
        $handler = $this->provider->getActiveHandler();

        return null === $handler ? RawDatabaseMutationResult::failure('raw.udb.error') : $this->map($handler->del($path));
    }

    private function map(UdbRawCommandResult $result): RawDatabaseMutationResult
    {
        return $result->success
            ? RawDatabaseMutationResult::success()
            : RawDatabaseMutationResult::failure(
                $result->errorKey ?? 'raw.udb.error',
                SafeAuditMetadata::validate($result->errorParams),
            );
    }
}
