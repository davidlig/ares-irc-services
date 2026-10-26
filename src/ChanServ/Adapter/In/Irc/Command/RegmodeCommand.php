<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\RegistrationMode\RegistrationModeHandlerInterface;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;

final readonly class RegmodeCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    use ChanServArgumentDefinitionSupport;

    public function __construct(private RegistrationModeHandlerInterface $handler) {}

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
        return new CommandArgumentDefinition('regmode.syntax', [new Argument('mode', alternatives: ['STATUS', 'DIRECT', 'APPROVAL'])]);
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
        return 3;
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
        return false;
    }

    public function getRequiredPermission(): string
    {
        return ChanServPermission::REGMODE;
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
        $mode = strtoupper($context->args[0] ?? '');
        if ('STATUS' === $mode) {
            $current = $this->handler->current();
            $context->reply('regmode.status', ['%mode%' => $current->value]);

            return CommandOutcome::success(new IrcopAuditData(target: 'registration-mode', extra: ['mode' => $current->value]));
        }

        $newMode = RegistrationMode::tryFrom($mode);
        if (null === $newMode) {
            $context->reply('error.syntax', ['syntax' => $context->trans('regmode.syntax')]);

            return CommandOutcome::rejected();
        }
        $previous = $this->handler->change($newMode, $context->sender->nick);
        $context->reply('regmode.changed', ['%mode%' => $newMode->value]);

        return CommandOutcome::success(new IrcopAuditData(target: 'registration-mode', extra: ['previous' => $previous->value, 'mode' => $newMode->value]));
    }
}
