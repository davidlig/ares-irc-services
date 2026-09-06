<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Rename\RenameNick;
use App\NickServ\Application\UseCase\Rename\RenameNickHandlerInterface;
use App\NickServ\Application\UseCase\Rename\RenameNickOutcome;
use App\NickServ\Application\UseCase\Rename\RenameNickResult;
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

    public function getMinArgs(): int
    {
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return 'rename.syntax';
    }

    public function getHelpKey(): string
    {
        return 'rename.help';
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
                $context->reply('rename.not_online', ['nickname' => $result->targetNick]);

                return CommandOutcome::rejected();
            case RenameNickOutcome::CannotRenameRoot:
                $context->reply('rename.cannot_rename_root', ['nickname' => $result->targetNick]);

                return CommandOutcome::rejected();
            case RenameNickOutcome::CannotRenameOper:
                $context->reply('rename.cannot_rename_oper', ['nickname' => $result->targetNick]);

                return CommandOutcome::rejected();
            case RenameNickOutcome::CannotRenameService:
                $context->reply('rename.cannot_rename_service', ['nickname' => $result->targetNick]);

                return CommandOutcome::rejected();
            case RenameNickOutcome::Success:
                $this->logger->info('User renamed via RENAME command', [
                    'operator' => $context->sender->nick ?? '',
                    'target_nick' => $result->targetNick,
                    'target_uid' => $result->targetUid,
                ]);

                $context->reply('rename.success', [
                    'nickname' => $result->targetNick,
                    'new_nick' => $result->newNick,
                ]);

                return CommandOutcome::success(new IrcopAuditData(
                    target: $result->targetNick ?? '',
                    targetHost: $result->targetHost ?? '',
                    targetIp: $result->targetIp ?? '',
                ));
        }
    }
}
