<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\UseCase\ExecuteRaw\ExecuteRaw;
use App\OperServ\Application\UseCase\ExecuteRaw\ExecuteRawHandler;
use App\OperServ\Application\UseCase\ExecuteRaw\RawDatabaseExecutionOutcome;
use DateTimeImmutable;

final readonly class RawCommand implements OperServCommandInterface
{
    public function __construct(private ExecuteRawHandler $handler) {}

    public function getName(): string
    {
        return 'RAW';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'raw.syntax';
    }

    public function getHelpKey(): string
    {
        return 'raw.help';
    }

    public function getOrder(): int
    {
        return 40;
    }

    public function getShortDescKey(): string
    {
        return 'raw.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return 'operserv.raw';
    }

    public function execute(OperServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $result = $this->handler->handle(new ExecuteRaw(
            actorNickname: $context->sender->nick,
            arguments: $context->args,
            occurredAt: new DateTimeImmutable(),
        ));
        switch ($result->outcome) {
            case RawDatabaseExecutionOutcome::Executed:
                $context->reply('raw.done');

                return;
            case RawDatabaseExecutionOutcome::DatabaseExecuted:
                $context->reply('raw.udb.done');

                return;
            case RawDatabaseExecutionOutcome::Empty:
                $context->reply('raw.empty');

                return;
            case RawDatabaseExecutionOutcome::TooLong:
                $context->reply('raw.too_long');

                return;
            case RawDatabaseExecutionOutcome::Disconnected:
                $context->reply('raw.not_connected');

                return;
            case RawDatabaseExecutionOutcome::DatabaseTargetInvalid:
                $context->reply('raw.udb.target', ['target' => $result->recordPath ?? '']);

                return;
            case RawDatabaseExecutionOutcome::DatabaseSyntaxInvalid:
                $context->reply('raw.udb.syntax');

                return;
            case RawDatabaseExecutionOutcome::DatabaseUnsupported:
                $context->reply('raw.udb.unsupported');

                return;
            case RawDatabaseExecutionOutcome::DatabaseRecordTypeInvalid:
                $context->reply('raw.udb.invalid_block', ['block' => $result->recordType ?? '']);

                return;
            case RawDatabaseExecutionOutcome::DatabasePathInvalid:
                $context->reply('raw.udb.invalid_path', ['path' => $result->recordPath ?? '']);

                return;
            case RawDatabaseExecutionOutcome::DatabaseValueInvalid:
                $context->reply('raw.udb.invalid_value', ['path' => $result->recordPath ?? '']);

                return;
            case RawDatabaseExecutionOutcome::DatabaseFailed:
                $context->reply('raw.udb.error');

                return;
        }
    }
}
