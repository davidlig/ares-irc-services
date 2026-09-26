<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\List\ListedRegisteredChannel;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannels;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannelsHandlerInterface;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannelsResult;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;

use function ceil;
use function count;
use function filter_var;
use function max;

use const FILTER_VALIDATE_INT;

final readonly class ListCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private ListRegisteredChannelsHandlerInterface $handler) {}

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
        return 210;
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
        return ChanServPermission::LIST;
    }

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

    public function allowsForbiddenChannel(): bool
    {
        return true;
    }

    public function usesLevelFounder(): bool
    {
        return false;
    }

    public function execute(ChanServContext $context): CommandOutcome
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

        $result = $this->handler->handle(new ListRegisteredChannels($context->args[0], $page));
        $this->present($context, $result);

        return CommandOutcome::success(new IrcopAuditData(
            target: $result->pattern,
            extra: ['page' => $result->page, 'total' => $result->total],
        ));
    }

    private function present(ChanServContext $context, ListRegisteredChannelsResult $result): void
    {
        $context->reply('list.header');

        if ([] === $result->entries && 0 === $result->total) {
            $context->reply('list.empty', ['pattern' => $result->pattern]);
        } elseif ([] !== $result->entries) {
            foreach ($result->entries as $entry) {
                $this->presentEntry($context, $entry);
            }
        }

        $context->reply('list.page', [
            'page' => $result->page,
            'pages' => (int) ceil($result->total / $result->pageSize),
            'total' => $result->total,
        ]);
    }

    private function presentEntry(ChanServContext $context, ListedRegisteredChannel $entry): void
    {
        $statusKey = match ($entry->status) {
            ChannelStatus::Active => 'list.status_active',
            ChannelStatus::Suspended => 'list.status_suspended',
            ChannelStatus::PendingDeletion => 'list.status_pending_deletion',
            ChannelStatus::Forbidden => 'list.status_forbidden',
        };

        $context->reply('list.row', [
            'channel' => $entry->channelName,
            'founder' => $entry->founderName ?? '—',
            'registered' => $context->formatDate($entry->registeredAt),
            'last_used' => $context->formatDate($entry->lastUsedAt),
            'status' => $context->trans($statusKey),
        ]);
    }

    private function rejectSyntax(ChanServContext $context): CommandOutcome
    {
        $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

        return CommandOutcome::rejected();
    }
}
