<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\Whoip\FindNicknamesByLastConnectIp;
use App\NickServ\Application\UseCase\Whoip\FindNicknamesByLastConnectIpHandlerInterface;

use function count;
use function inet_ntop;
use function inet_pton;

final readonly class WhoipCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(private FindNicknamesByLastConnectIpHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'WHOIP';
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
        return 'whoip.syntax';
    }

    public function getHelpKey(): string
    {
        return 'whoip.help';
    }

    public function getOrder(): int
    {
        return 61;
    }

    public function getShortDescKey(): string
    {
        return 'whoip.short';
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
        return NickServPermission::WHOIP;
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

        if (1 !== count($context->args)) {
            return $this->rejectSyntax($context);
        }

        $packedIp = inet_pton($context->args[0]);
        $ip = false === $packedIp ? false : inet_ntop($packedIp);
        if (false === $ip) {
            return $this->rejectSyntax($context);
        }

        $nicknames = $this->handler->handle(new FindNicknamesByLastConnectIp($ip));
        if ([] === $nicknames) {
            $context->reply('whoip.empty');
        } else {
            $context->reply('whoip.header');
            foreach ($nicknames as $nickname) {
                $context->replyRaw($nickname);
            }
        }

        return CommandOutcome::success(new IrcopAuditData(
            target: $ip,
            targetIp: $ip,
            extra: ['matches' => count($nicknames)],
        ));
    }

    private function rejectSyntax(NickServContext $context): CommandOutcome
    {
        $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

        return CommandOutcome::rejected();
    }
}
