<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Forbid\ForbidNick;
use App\NickServ\Application\UseCase\Forbid\ForbidNickHandler;
use App\NickServ\Application\UseCase\Forbid\ForbidNickOutcome;

use function array_slice;
use function implode;
use function trim;

final class ForbidCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly ForbidNickHandler $handler,
    ) {}

    public function getName(): string
    {
        return 'FORBID';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'forbid.syntax';
    }

    public function getHelpKey(): string
    {
        return 'forbid.help';
    }

    public function getOrder(): int
    {
        return 69;
    }

    public function getShortDescKey(): string
    {
        return 'forbid.short';
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
        return NickServPermission::FORBID;
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

        $targetNick = $context->args[0];
        $reason = trim(implode(' ', array_slice($context->args, 1)));

        if ('' === $reason) {
            $context->reply('forbid.reason_required');

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new ForbidNick($context->sender->nick, $targetNick, $reason));

        return match ($result->outcome) {
            ForbidNickOutcome::Forbidden => $this->replySuccess($context, $result->targetNickname, $result->reason),
            ForbidNickOutcome::ReasonUpdated => $this->replyRejected($context, 'forbid.updated', $result->targetNickname),
            ForbidNickOutcome::TargetIsRoot => $this->replyRejected($context, 'forbid.cannot_forbid_root', $result->targetNickname),
            ForbidNickOutcome::TargetIsIrcop => $this->replyRejected($context, 'forbid.cannot_forbid_oper', $result->targetNickname),
            ForbidNickOutcome::TargetIsService => $this->replyRejected($context, 'forbid.cannot_forbid_service', $result->targetNickname),
        };
    }

    private function replyRejected(NickServContext $context, string $key, string $nickname): CommandOutcome
    {
        $context->reply($key, ['%nickname%' => $nickname]);

        return CommandOutcome::rejected();
    }

    private function replySuccess(NickServContext $context, string $nickname, string $reason): CommandOutcome
    {
        $context->reply('forbid.success', ['%nickname%' => $nickname]);

        return CommandOutcome::success(new IrcopAuditData(target: $nickname, reason: $reason));
    }
}
