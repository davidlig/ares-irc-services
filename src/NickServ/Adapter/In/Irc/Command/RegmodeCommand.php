<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\RegistrationMode\RegistrationModeHandlerInterface;
use App\NickServ\Application\UseCase\RegistrationMode\RegistrationModeInput;
use App\NickServ\Domain\ValueObject\RegistrationMode;

use function count;
use function strtolower;
use function strtoupper;

final readonly class RegmodeCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private RegistrationModeHandlerInterface $handler, private NickServOperatorAccess $operatorAccess) {}

    public function getName(): string
    {
        return 'REGMODE';
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
        return 'regmode.syntax';
    }

    public function getHelpKey(): string
    {
        return 'regmode.help';
    }

    public function getOrder(): int
    {
        return 67;
    }

    public function getShortDescKey(): string
    {
        return 'regmode.short';
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
        return NickServPermission::REGMODE;
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
        $action = strtoupper($context->args[0] ?? '');
        $mode = RegistrationMode::tryFrom($action);
        if (1 !== count($context->args) || (null === $mode && 'STATUS' !== $action)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }
        $authorized = $this->operatorAccess->hasPermission(
            strtolower($sender->nick),
            $context->getSenderAccountId(),
            $sender->isIdentified,
            $sender->isOper,
            NickServPermission::REGMODE,
        );
        $result = $this->handler->handle(new RegistrationModeInput($authorized, $mode));
        if (!$result->authorized || null === $result->mode) {
            $context->reply('error.permission_denied');

            return CommandOutcome::rejected();
        }
        $context->reply($result->changed ? 'regmode.changed' : 'regmode.status', ['mode' => $result->mode->value]);

        return CommandOutcome::success($result->changed ? new IrcopAuditData(
            target: 'network',
            extra: ['option' => 'REGMODE', 'value' => $result->mode->value],
        ) : null);
    }
}
