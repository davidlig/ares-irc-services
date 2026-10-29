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
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

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

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition('regmode.syntax', [
            new Argument('action', alternatives: ['EMAIL', 'TOKEN', 'STATUS']),
        ], allowExtra: false);
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
        return 'regmode.help';
    }

    public function getExampleKey(): string
    {
        return 'regmode.example';
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

        $validation = CommandArgumentValidator::validate($this->getArgumentDefinition(), $context->args);
        if (null !== $validation->syntaxKey) {
            $context->replySyntax($validation->syntaxKey);

            return CommandOutcome::rejected();
        }

        $action = strtoupper($context->args[0] ?? '');
        $mode = RegistrationMode::tryFrom($action);
        $authorized = $this->operatorAccess->hasPermission(
            strtolower($sender->nick),
            $context->getSenderAccountId(),
            $sender->isIdentified,
            $sender->isOper,
            NickServPermission::REGMODE,
        );
        $result = $this->handler->handle(new RegistrationModeInput($authorized, $mode));
        if (!$result->authorized || null === $result->mode) {
            $context->replyCommonError('error.permission_denied');

            return CommandOutcome::rejected();
        }
        $context->reply(
            $result->changed ? 'regmode.changed' : 'regmode.status',
            ['mode' => new Fragment($result->mode->value, Component::Emphasis)],
            $result->changed ? Role::Success : Role::Information,
        );

        return CommandOutcome::success($result->changed ? new IrcopAuditData(
            target: 'network',
            extra: ['option' => 'REGMODE', 'value' => $result->mode->value],
        ) : null);
    }
}
