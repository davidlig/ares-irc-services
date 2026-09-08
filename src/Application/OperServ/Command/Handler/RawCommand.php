<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\UdbRawCommandHandlerInterface;
use App\Application\Port\UdbRawCommandHandlerProviderInterface;
use App\Application\Port\UdbRawCommandResult;

use function array_slice;
use function count;
use function implode;
use function in_array;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

final class RawCommand implements OperServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly ActiveConnectionHolderInterface $connectionHolder,
        private readonly UdbRawCommandHandlerProviderInterface $udbCommands,
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
        return OperServPermission::RAW;
    }

    public function execute(OperServContext $context): CommandOutcome
    {
        $sender = $context->getSender();
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $rawLine = implode(' ', $context->args);

        $errorKey = $this->validateRawLine($context, $rawLine);
        if (null !== $errorKey) {
            return CommandOutcome::rejected();
        }

        $udbOutcome = $this->interceptUdbMutation($context);
        if (null !== $udbOutcome) {
            return $udbOutcome;
        }

        $this->connectionHolder->writeLine($rawLine);

        $commandName = $this->commandName($context->args);

        $auditData = new IrcopAuditData(
            target: $commandName,
            reason: sprintf('Executed by %s', $sender->nick),
            extra: ['transport' => 'irc'],
        );

        $context->reply('raw.done');

        return CommandOutcome::success($auditData);
    }

    /** @param array<string> $args */
    private function commandName(array $args): string
    {
        $first = $args[0] ?? '';

        return strtoupper(str_starts_with($first, ':') ? ($args[1] ?? 'UNKNOWN') : ($first ?: 'UNKNOWN'));
    }

    /**
     * When the UDB capability is available, intercepted DB mutations are
     * validated and applied instead of being written blindly to the socket.
     */
    private function interceptUdbMutation(OperServContext $context): ?CommandOutcome
    {
        $handler = $this->udbCommands->getActiveHandler();
        if (null === $handler) {
            return null;
        }

        $args = array_values($context->args);
        if (count($args) < 3 || 'DB' !== strtoupper($args[0])) {
            return null;
        }

        $subcommand = strtoupper($args[2]);

        // Other DB frames keep the classic (dangerous) raw behavior.
        if (!in_array($subcommand, ['INS', 'DEL', 'DRP', 'OPT'], true)) {
            return null;
        }

        if ('*' !== $args[1]) {
            $context->reply('raw.udb.target', ['%target%' => $args[1]]);

            return CommandOutcome::rejected();
        }

        if ('INS' === $subcommand) {
            return $this->handleIns($context, $args, $handler);
        }

        if ('DEL' === $subcommand) {
            return $this->handleDel($context, $args, $handler);
        }

        $context->reply('raw.udb.unsupported');

        return CommandOutcome::rejected();
    }

    /**
     * @param list<string> $args
     */
    private function handleIns(OperServContext $context, array $args, UdbRawCommandHandlerInterface $handler): CommandOutcome
    {
        if (count($args) < 5 || '' === trim($args[3])) {
            $context->reply('raw.udb.syntax');

            return CommandOutcome::rejected();
        }

        $value = $this->decodeValue(implode(' ', array_slice($args, 4)));

        return $this->applyUdbResult($context, $handler->ins($args[3], $value), 'INS');
    }

    /**
     * @param list<string> $args
     */
    private function handleDel(OperServContext $context, array $args, UdbRawCommandHandlerInterface $handler): CommandOutcome
    {
        if (4 !== count($args) || '' === trim($args[3])) {
            $context->reply('raw.udb.syntax');

            return CommandOutcome::rejected();
        }

        return $this->applyUdbResult($context, $handler->del($args[3]), 'DEL');
    }

    private function applyUdbResult(OperServContext $context, UdbRawCommandResult $result, string $subcommand): CommandOutcome
    {
        if (!$result->success) {
            $context->reply($result->errorKey ?? 'raw.udb.error', $result->errorParams);

            return CommandOutcome::rejected();
        }

        $sender = $context->getSender();

        $auditData = new IrcopAuditData(
            target: 'DB ' . $subcommand,
            reason: sprintf('Executed by %s', $sender->nick ?? 'unknown'),
            extra: ['transport' => 'udb'],
        );

        $context->reply('raw.udb.done');

        return CommandOutcome::success($auditData);
    }

    /** Strips an optional IRC trailing colon and optional surrounding double quotes. */
    private function decodeValue(string $value): string
    {
        if (str_starts_with($value, ':')) {
            $value = substr($value, 1);
        }

        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }

    private function validateRawLine(OperServContext $context, string $rawLine): ?string
    {
        return (function () use ($context, $rawLine): ?string {
            if ('' === trim($rawLine)) {
                $context->reply('raw.empty');

                return 'empty';
            }

            if (strlen($rawLine) > 510) {
                $context->reply('raw.too_long');

                return 'too_long';
            }

            if (!$this->connectionHolder->isConnected()) {
                $context->reply('raw.not_connected');

                return 'not_connected';
            }

            return null;
        })();
    }
}
