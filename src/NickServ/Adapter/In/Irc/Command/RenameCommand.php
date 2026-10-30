<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Rename\RenameNick;
use App\NickServ\Application\UseCase\Rename\RenameNickHandlerInterface;
use App\NickServ\Application\UseCase\Rename\RenameNickOutcome;
use App\NickServ\Application\UseCase\Rename\RenameNickResult;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use Psr\Log\LoggerInterface;

final readonly class RenameCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private RenameNickHandlerInterface $handler,
        private NetworkUserLookupPort $userLookup,
        private LoggerInterface $logger,
        private string $guestPrefix = 'Guest-',
    ) {}

    public function getName(): string
    {
        return 'RENAME';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('rename.syntax', [new Argument('nickname')]);
    }

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return 'rename.help';
    }

    public function getExampleKey(): string
    {
        return 'rename.example';
    }

    public function getOrder(): int
    {
        return 65;
    }

    public function getShortDescKey(): string
    {
        return 'rename.short';
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
        return NickServPermission::RENAME;
    }

    public function getHelpParams(): array
    {
        return ['%prefix%' => $this->guestPrefix];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $targetNick = $context->args[0];
        $onlineUser = $this->userLookup->findByNick($targetNick);

        $result = $this->handler->handle(new RenameNick(
            targetNick: $targetNick,
            targetUid: $onlineUser?->uid,
            targetIdent: $onlineUser?->ident,
            targetHostname: $onlineUser?->hostname,
            targetIp: $onlineUser?->ipBase64,
            operatorNick: $sender->nick,
        ));

        return $this->present($context, $result);
    }

    private function present(NickServContext $context, RenameNickResult $result): CommandOutcome
    {
        switch ($result->outcome) {
            case RenameNickOutcome::NotOnline:
                $context->replyMissingNick('error.nick_not_online', $result->targetNick ?? '');

                return CommandOutcome::rejected();
            case RenameNickOutcome::CannotRenameRoot:
                $context->replyEmphasized('rename.cannot_rename_root', ['nickname' => $result->targetNick], Role::Error, ['nickname']);

                return CommandOutcome::rejected();
            case RenameNickOutcome::CannotRenameOper:
                $context->replyEmphasized('rename.cannot_rename_oper', ['nickname' => $result->targetNick], Role::Error, ['nickname']);

                return CommandOutcome::rejected();
            case RenameNickOutcome::CannotRenameService:
                $context->replyEmphasized('rename.cannot_rename_service', ['nickname' => $result->targetNick], Role::Error, ['nickname']);

                return CommandOutcome::rejected();
            case RenameNickOutcome::Success:
                $this->logger->info('User renamed via RENAME command', [
                    'operator' => $context->sender->nick ?? '',
                    'target_nick' => $result->targetNick,
                    'target_uid' => $result->targetUid,
                ]);

                $context->replyEmphasized('rename.success', [
                    'nickname' => $result->targetNick,
                    'new_nick' => $result->newNick,
                ], Role::Success, ['new_nick', 'nickname']);

                return CommandOutcome::success(new IrcopAuditData(
                    target: $result->targetNick ?? '',
                    targetHost: $result->targetHost ?? '',
                    targetIp: $result->targetIp ?? '',
                ));
        }
    }
}
