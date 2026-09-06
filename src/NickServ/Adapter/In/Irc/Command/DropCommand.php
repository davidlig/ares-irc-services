<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\AuthorizationCheckerInterface;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Drop\DropNick;
use App\NickServ\Application\UseCase\Drop\DropNickHandlerInterface;
use App\NickServ\Application\UseCase\Drop\DropNickOutcome;
use App\NickServ\Application\UseCase\Drop\DropNickResult;
use Psr\Log\LoggerInterface;

use function sprintf;
use function strcasecmp;

final readonly class DropCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private DropNickHandlerInterface $handler,
        private LoggerInterface $logger,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {}

    public function getName(): string
    {
        return 'DROP';
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
        return 'drop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'drop.help';
    }

    public function getOrder(): int
    {
        return 71;
    }

    public function getShortDescKey(): string
    {
        return 'drop.short';
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
        return NickServPermission::DROP;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        $sender = $context->sender;
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $targetNick = $context->args[0];
        $force = isset($context->args[1]) && 0 === strcasecmp($context->args[1], 'force');
        $forceAllowed = null !== $this->authorizationChecker && $this->authorizationChecker->isGranted(NickServPermission::DROP_FORCE, $context);

        $result = $this->handler->handle(new DropNick(
            targetNick: $targetNick,
            operatorNick: $sender->nick,
            force: $force,
            forceAllowed: $forceAllowed,
        ));

        return $this->present($context, $result);
    }

    private function present(NickServContext $context, DropNickResult $result): CommandOutcome
    {
        switch ($result->outcome) {
            case DropNickOutcome::CannotDropSelf:
                $context->reply('drop.cannot_drop_self');

                return CommandOutcome::rejected();
            case DropNickOutcome::NotRegistered:
                $context->reply('drop.not_registered', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case DropNickOutcome::PendingDeletion:
                $context->reply('drop.pending_deletion', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case DropNickOutcome::ForcePermissionDenied:
                $context->reply('error.permission_denied');

                return CommandOutcome::rejected();
            case DropNickOutcome::Suspended:
                $context->reply('drop.suspended', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case DropNickOutcome::Forbidden:
                $context->reply('drop.forbidden', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case DropNickOutcome::CannotDropRoot:
                $context->reply('drop.cannot_drop_root', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case DropNickOutcome::CannotDropOper:
                $context->reply('drop.cannot_drop_oper', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case DropNickOutcome::CannotDropService:
                $context->reply('drop.cannot_drop_service', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case DropNickOutcome::SoftDropSuccess:
                $this->logger->info(sprintf('NickServ DROP: %s soft-dropped by %s', $result->nickname ?? '', $context->sender->nick ?? ''));
                $context->reply('drop.success', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::success(new IrcopAuditData(target: $result->nickname ?? ''));
            case DropNickOutcome::HardDropSuccess:
                $this->logger->info(sprintf('NickServ DROP: %s hard-dropped by %s', $result->nickname ?? '', $context->sender->nick ?? ''));
                $context->reply('drop.force_success', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::success(new IrcopAuditData(target: $result->nickname ?? '', extra: ['force' => true]));
        }
    }
}
