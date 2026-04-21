<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\Port\ActiveConnectionHolderInterface;
use Psr\Log\LoggerInterface;

use function implode;
use function sprintf;
use function strlen;
use function trim;

final class RawCommand implements OperServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly ActiveConnectionHolderInterface $connectionHolder,
        private readonly LoggerInterface $logger,
    ) {
    }

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

        if ('' === trim($rawLine)) {
            $context->reply('raw.empty');

            return;
        }

        if (strlen($rawLine) > 510) {
            $context->reply('raw.too_long');

            return;
        }

        if (!$this->connectionHolder->isConnected()) {
            $context->reply('raw.not_connected');

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
}
