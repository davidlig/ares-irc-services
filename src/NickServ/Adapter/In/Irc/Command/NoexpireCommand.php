<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNick;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickHandlerInterface;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickOutcome;
use App\NickServ\Application\UseCase\Noexpire\SetNoexpireNickResult;

use function in_array;
use function strtoupper;

final readonly class NoexpireCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private SetNoexpireNickHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'NOEXPIRE';
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
        return 'noexpire.syntax';
    }

    public function getHelpKey(): string
    {
        return 'noexpire.help';
    }

    public function getOrder(): int
    {
        return 66;
    }

    public function getShortDescKey(): string
    {
        return 'noexpire.short';
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
        return NickServPermission::NOEXPIRE;
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

        $action = strtoupper($context->args[1]);
        if (!in_array($action, ['ON', 'OFF'], true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return CommandOutcome::rejected();
        }

        $result = $this->handler->handle(new SetNoexpireNick(
            nickname: $context->args[0],
            noexpire: 'ON' === $action,
        ));

        return $this->present($context, $result);
    }

    private function present(NickServContext $context, SetNoexpireNickResult $result): CommandOutcome
    {
        switch ($result->outcome) {
            case SetNoexpireNickOutcome::NotRegistered:
                $context->reply('noexpire.not_registered', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case SetNoexpireNickOutcome::Forbidden:
                $context->reply('noexpire.forbidden', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case SetNoexpireNickOutcome::Suspended:
                $context->reply('noexpire.suspended', ['%nickname%' => $result->nickname ?? '']);

                return CommandOutcome::rejected();
            case SetNoexpireNickOutcome::Success:
                $context->reply(
                    $result->noexpire ? 'noexpire.success_on' : 'noexpire.success_off',
                    ['%nickname%' => $result->nickname ?? ''],
                );

                return CommandOutcome::success(new IrcopAuditData(
                    target: $result->nickname ?? '',
                    extra: ['option' => $result->noexpire ? 'ON' : 'OFF'],
                ));
        }
    }
}
