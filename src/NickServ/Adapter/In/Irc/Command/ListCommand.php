<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\List\ListedNickAccount;
use App\NickServ\Application\UseCase\List\ListNickAccounts;
use App\NickServ\Application\UseCase\List\ListNickAccountsHandlerInterface;
use App\NickServ\Application\UseCase\List\ListNickAccountsResult;
use App\NickServ\Domain\ValueObject\NickStatus;

use function ceil;
use function count;
use function filter_var;

use const FILTER_VALIDATE_INT;

final readonly class ListCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private ListNickAccountsHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'LIST';
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
        return 'list.syntax';
    }

    public function getHelpKey(): string
    {
        return 'list.help';
    }

    public function getOrder(): int
    {
        return 85;
    }

    public function getShortDescKey(): string
    {
        return 'list.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return NickServPermission::LIST;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        if (1 > count($context->args) || 2 < count($context->args)) {
            return $this->rejectSyntax($context);
        }

        $page = 1;
        if (isset($context->args[1])) {
            $parsedPage = filter_var($context->args[1], FILTER_VALIDATE_INT);
            if (false === $parsedPage) {
                return $this->rejectSyntax($context);
            }
            $page = max(1, $parsedPage);
        }

        $result = $this->handler->handle(new ListNickAccounts($context->args[0], $page));
        $this->present($context, $result);

        return CommandOutcome::success(new IrcopAuditData(
            target: $result->pattern,
            extra: ['page' => $result->page, 'total' => $result->total],
        ));
    }

    private function present(NickServContext $context, ListNickAccountsResult $result): void
    {
        $context->reply('list.header');

        if ([] === $result->entries && 0 === $result->total) {
            $context->reply('list.empty', ['pattern' => $result->pattern]);
        } elseif ([] !== $result->entries) {
            foreach ($result->entries as $entry) {
                $this->presentEntry($context, $entry);
            }
        }

        $pages = (int) ceil($result->total / $result->pageSize);
        $context->reply('list.page', [
            'page' => $result->page,
            'pages' => $pages,
            'total' => $result->total,
        ]);
    }

    private function presentEntry(NickServContext $context, ListedNickAccount $entry): void
    {
        $statusKey = match ($entry->status) {
            NickStatus::Pending => 'list.status_pending',
            NickStatus::Registered => 'list.status_registered',
            NickStatus::Suspended => 'list.status_suspended',
            NickStatus::PendingDeletion => 'list.status_pending_deletion',
            NickStatus::Forbidden => 'list.status_forbidden',
        };

        $context->reply('list.row', [
            'nickname' => $entry->nickname,
            'registered' => $context->formatDate($entry->registeredAt),
            'last_seen' => $context->formatDate($entry->lastSeenAt),
            'ip' => $entry->lastConnectIp ?? '—',
            'status' => $context->trans($statusKey),
        ]);
    }

    private function rejectSyntax(NickServContext $context): CommandOutcome
    {
        $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

        return CommandOutcome::rejected();
    }
}
