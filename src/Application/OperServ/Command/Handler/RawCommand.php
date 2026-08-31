<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\UdbRawCommandHandlerInterface;
use App\Application\Port\UdbRawCommandResult;
use Psr\Log\LoggerInterface;

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

final class RawCommand implements OperServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly ActiveConnectionHolderInterface $connectionHolder,
        private readonly LoggerInterface $logger,
        private readonly ?UdbRawCommandHandlerInterface $udbCommands = null,
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

    public function getRequiredPermission(): ?string
    {
        return OperServPermission::RAW;
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }

    public function execute(OperServContext $context): void
    {
        $sender = $context->getSender();
        if (null === $sender) {
            return;
        }

        $rawLine = implode(' ', $context->args);

        $errorKey = $this->validateRawLine($context, $rawLine);
        if (null !== $errorKey) {
            return;
        }

        if ($this->interceptUdbMutation($context)) {
            return;
        }

        $this->connectionHolder->writeLine($rawLine);

        $this->logger->warning('RAW command executed', [
            'operator' => $sender->nick,
            'line' => $rawLine,
        ]);

        $this->auditData = new IrcopAuditData(
            target: $rawLine,
            reason: sprintf('Executed by %s', $sender->nick),
        );

        $context->reply('raw.done');
    }

    /**
     * When the active protocol is UDB-capable (unrealudb), intercepted DB
     * mutations are validated and applied against the authoritative services
     * store instead of being written blindly to the socket. Returns true when
     * the line was handled (including rejected with a reply).
     */
    private function interceptUdbMutation(OperServContext $context): bool
    {
        if (null === $this->udbCommands) {
            return false;
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module || 'unrealudb' !== $module->getProtocolName()) {
            return false;
        }

        $args = $context->args;
        if (count($args) < 3 || 'DB' !== strtoupper($args[0])) {
            return false;
        }

        $subcommand = strtoupper($args[2]);

        // Other DB frames keep the classic (dangerous) raw behavior.
        if (!in_array($subcommand, ['INS', 'DEL', 'DRP', 'OPT'], true)) {
            return false;
        }

        if ('*' !== $args[1]) {
            $context->reply('raw.udb.target', ['%target%' => $args[1]]);

            return true;
        }

        if ('INS' === $subcommand) {
            $this->handleIns($context, $args);

            return true;
        }

        if ('DEL' === $subcommand) {
            $this->handleDel($context, $args);

            return true;
        }

        $context->reply('raw.udb.unsupported');

        return true;
    }

    /**
     * @param list<string> $args
     */
    private function handleIns(OperServContext $context, array $args): void
    {
        if (count($args) < 5 || '' === trim($args[3] ?? '')) {
            $context->reply('raw.udb.syntax');

            return;
        }

        $value = $this->decodeValue(implode(' ', array_slice($args, 4)));

        $this->applyUdbResult($context, $this->udbCommands->ins($args[3], $value));
    }

    /**
     * @param list<string> $args
     */
    private function handleDel(OperServContext $context, array $args): void
    {
        if (4 !== count($args) || '' === trim($args[3] ?? '')) {
            $context->reply('raw.udb.syntax');

            return;
        }

        $this->applyUdbResult($context, $this->udbCommands->del($args[3]));
    }

    private function applyUdbResult(OperServContext $context, UdbRawCommandResult $result): void
    {
        if (!$result->success) {
            $context->reply($result->errorKey ?? 'raw.udb.error', $result->errorParams);

            return;
        }

        $sender = $context->getSender();
        $auditLine = $result->auditLine ?? '';

        $this->logger->warning('RAW UDB mutation applied', [
            'operator' => $sender?->nick,
            'line' => $auditLine,
        ]);

        $this->auditData = new IrcopAuditData(
            target: $auditLine,
            reason: sprintf('Executed by %s', $sender?->nick ?? 'unknown'),
        );

        $context->reply('raw.udb.done');
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
