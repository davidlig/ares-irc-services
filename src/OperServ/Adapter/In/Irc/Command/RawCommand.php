<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use DateTimeImmutable;

final readonly class RawCommand implements OperServCommandInterface
{
    public function __construct(
        private RawCommandExecutor $executor,
        private CommandAuditRecorder $audit,
    ) {}

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

        $result = $this->executor->execute($context->args);
        switch ($result->outcome) {
            case RawCommandExecutionOutcome::Sent:
                $this->recordAudit($context->sender->nick, $result->operation ?? 'UNKNOWN', 'irc');
                $context->reply('raw.done');

                return;
            case RawCommandExecutionOutcome::Intercepted:
                $this->recordAudit($context->sender->nick, $result->operation ?? 'UNKNOWN', 'protocol');
                $context->reply('raw.protocol.done');

                return;
            case RawCommandExecutionOutcome::Empty:
                $context->reply('raw.empty');

                return;
            case RawCommandExecutionOutcome::TooLong:
                $context->reply('raw.too_long');

                return;
            case RawCommandExecutionOutcome::Disconnected:
                $context->reply('raw.not_connected');

                return;
            case RawCommandExecutionOutcome::TargetInvalid:
                $context->reply('raw.protocol.target', ['target' => $result->resourceIdentifier ?? '']);

                return;
            case RawCommandExecutionOutcome::SyntaxInvalid:
                $context->reply('raw.protocol.syntax');

                return;
            case RawCommandExecutionOutcome::Unsupported:
                $context->reply('raw.protocol.unsupported');

                return;
            case RawCommandExecutionOutcome::ResourceTypeInvalid:
                $context->reply('raw.protocol.invalid_resource_type', ['resource_type' => $result->resourceType ?? '']);

                return;
            case RawCommandExecutionOutcome::ResourceIdentifierInvalid:
                $context->reply('raw.protocol.invalid_resource', ['resource' => $result->resourceIdentifier ?? '']);

                return;
            case RawCommandExecutionOutcome::ValueInvalid:
                $context->reply('raw.protocol.invalid_value', ['resource' => $result->resourceIdentifier ?? '']);

                return;
            case RawCommandExecutionOutcome::Failed:
                $context->reply('raw.protocol.error');

                return;
        }
    }

    private function recordAudit(string $actor, string $operation, string $transport): void
    {
        $this->audit->record(new CommandAuditRecord(
            category: CommandAuditCategory::OperatorAction,
            service: 'OperServ',
            actor: $actor,
            operation: 'RAW',
            occurredAt: new DateTimeImmutable(),
            target: $operation,
            permission: 'operserv.raw',
            metadata: ['transport' => $transport],
        ));
    }
}
