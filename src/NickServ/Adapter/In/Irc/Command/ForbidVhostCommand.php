<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\ForbidVhost\ForbiddenVhostAction;
use App\NickServ\Application\UseCase\ForbidVhost\ManageForbiddenVhost;
use App\NickServ\Application\UseCase\ForbidVhost\ManageForbiddenVhostHandler;
use App\NickServ\Application\UseCase\ForbidVhost\ManageForbiddenVhostOutcome;

use function count;
use function sprintf;
use function strtoupper;
use function trim;

final class ForbidVhostCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly ManageForbiddenVhostHandler $handler,
    ) {}

    public function getName(): string
    {
        return 'FORBIDVHOST';
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
        return 'forbidvhost.syntax';
    }

    public function getHelpKey(): string
    {
        return 'forbidvhost.help';
    }

    public function getOrder(): int
    {
        return 72;
    }

    public function getShortDescKey(): string
    {
        return 'forbidvhost.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'forbidvhost.add.short', 'help_key' => 'forbidvhost.add.help', 'syntax_key' => 'forbidvhost.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'forbidvhost.del.short', 'help_key' => 'forbidvhost.del.help', 'syntax_key' => 'forbidvhost.del.syntax'],
            ['name' => 'LIST', 'desc_key' => 'forbidvhost.list.short', 'help_key' => 'forbidvhost.list.help', 'syntax_key' => 'forbidvhost.list.syntax'],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return NickServPermission::FORBIDVHOST;
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

        $sub = strtoupper($context->args[0] ?? '');

        return match ($sub) {
            'ADD' => $this->doAdd($context),
            'DEL' => $this->doDel($context),
            'LIST' => $this->doList($context),
            default => $this->rejectUnknownSubcommand($context, $sub),
        };
    }

    private function rejectUnknownSubcommand(NickServContext $context, string $sub): CommandOutcome
    {
        $context->reply('forbidvhost.unknown_sub', ['%sub%' => $sub]);

        return CommandOutcome::rejected();
    }

    private function doAdd(NickServContext $context): CommandOutcome
    {
        $syntaxKey = 'forbidvhost.add.syntax';
        $pattern = count($context->args) >= 2 ? trim($context->args[1]) : '';

        if (count($context->args) < 2 || '' === $pattern) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans($syntaxKey)]);

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new ManageForbiddenVhost(
            ForbiddenVhostAction::Add,
            $pattern,
            $context->senderAccount?->getId(),
        ));

        return match ($result->outcome) {
            ManageForbiddenVhostOutcome::Added => $this->replySuccess($context, 'forbidvhost.add.done', $pattern),
            ManageForbiddenVhostOutcome::InvalidPattern => $this->replyPatternError($context, 'forbidvhost.add.invalid', $pattern),
            ManageForbiddenVhostOutcome::AlreadyExists => $this->replyPatternError($context, 'forbidvhost.add.already_exists', $pattern),
            default => CommandOutcome::rejected(),
        };
    }

    private function doDel(NickServContext $context): CommandOutcome
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('forbidvhost.del.syntax')]);

            return CommandOutcome::rejected();
        }

        $pattern = trim($context->args[1]);
        if ('' === $pattern) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('forbidvhost.del.syntax')]);

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new ManageForbiddenVhost(ForbiddenVhostAction::Delete, $pattern, null));
        if (ManageForbiddenVhostOutcome::NotFound === $result->outcome) {
            $context->reply('forbidvhost.del.not_found', ['%pattern%' => $pattern]);

            return CommandOutcome::rejected();
        }

        return $this->replySuccess($context, 'forbidvhost.del.done', $pattern);
    }

    private function doList(NickServContext $context): CommandOutcome
    {
        $result = $this->handler->handle(new ManageForbiddenVhost(ForbiddenVhostAction::List, null, null));
        if (ManageForbiddenVhostOutcome::Empty === $result->outcome) {
            $context->reply('forbidvhost.list.empty');

            return CommandOutcome::rejected();
        }

        $context->reply('forbidvhost.list.header', ['%count%' => (string) count($result->entries)]);

        $num = 1;
        foreach ($result->entries as $forbidden) {
            $creatorName = $this->resolveCreatorName($forbidden->creatorNickId, $context);
            $createdAt = $context->formatDate($forbidden->createdAt);

            $context->reply('forbidvhost.list.entry', [
                '%index%' => (string) $num,
                '%pattern%' => sprintf("\x0304%s\x03", $forbidden->pattern),
                '%nickname%' => $creatorName,
                '%date%' => $createdAt,
            ]);
            ++$num;
        }

        return CommandOutcome::rejected();
    }

    private function replyPatternError(NickServContext $context, string $key, string $pattern): CommandOutcome
    {
        $context->reply($key, ['%pattern%' => $pattern]);

        return CommandOutcome::rejected();
    }

    private function replySuccess(NickServContext $context, string $key, string $pattern): CommandOutcome
    {
        $context->reply($key, ['%pattern%' => $pattern]);

        return CommandOutcome::success(new IrcopAuditData(target: $pattern));
    }

    private function resolveCreatorName(?int $creatorNickId, NickServContext $context): string
    {
        if (null === $creatorNickId) {
            return $context->trans('forbidvhost.list.unknown_creator');
        }

        $creator = $context->senderAccount?->getId() === $creatorNickId
            ? $context->senderAccount
            : null;

        return null !== $creator ? $creator->getNickname() : $context->trans('forbidvhost.list.unknown_creator');
    }
}
