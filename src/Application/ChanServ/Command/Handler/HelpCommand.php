<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;

/**
 * HELP [command [sub-option]].
 *
 * Lists commands (filtered by IRCd mode support) or shows help for a command.
 */
final readonly class HelpCommand implements ChanServCommandInterface
{
    private const int CMD_PAD = 12;

    private const int HEADER_WIDTH = 40;

    /** Commands that require specific mode support to show (name => mode letter). */
    private const array MODE_DEPENDENT_COMMANDS = [
        'ADMIN' => 'a',
        'DEADMIN' => 'a',
        'HALFOP' => 'h',
        'DEHALFOP' => 'h',
    ];

    public function getName(): string
    {
        return 'HELP';
    }

    public function getAliases(): array
    {
        return ['?'];
    }

    public function getMinArgs(): int
    {
        return 0;
    }

    public function getSyntaxKey(): string
    {
        return 'help.syntax';
    }

    public function getHelpKey(): string
    {
        return 'help.help';
    }

    public function getOrder(): int
    {
        return 99;
    }

    public function getShortDescKey(): string
    {
        return 'help.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return null;
    }

    public function execute(ChanServContext $context): void
    {
        if (empty($context->args)) {
            $this->showGeneralHelp($context);

            return;
        }

        $targetCmd = strtoupper($context->args[0]);
        $handler = $context->getRegistry()->find($targetCmd);

        if (null === $handler) {
            $context->reply('help.unknown_command', ['%command%' => $targetCmd]);

            return;
        }

        if (isset($context->args[1]) && [] !== $handler->getSubCommandHelp()) {
            $subName = strtoupper($context->args[1]);
            foreach ($handler->getSubCommandHelp() as $sub) {
                if ($sub['name'] === $subName) {
                    $context->replyRaw($context->trans($sub['syntax_key']));
                    $context->replyRaw($context->trans($sub['help_key']));

                    return;
                }
            }
        }

        $context->replyRaw($context->trans($handler->getSyntaxKey()));
        $context->replyRaw($context->trans($handler->getHelpKey()));
        if ([] !== $handler->getSubCommandHelp()) {
            $context->replyRaw($context->trans('help.suboptions'));
            foreach ($handler->getSubCommandHelp() as $sub) {
                $context->replyRaw('  ' . str_pad($sub['name'], self::CMD_PAD) . ' ' . $context->trans($sub['desc_key']));
            }
        }
    }

    private function showGeneralHelp(ChanServContext $context): void
    {
        $context->replyRaw($context->trans('help.intro'));
        $context->replyRaw(str_repeat('–', self::HEADER_WIDTH));

        $commands = $context->getRegistry()->all();
        usort($commands, static fn (ChanServCommandInterface $a, ChanServCommandInterface $b): int => $a->getOrder() <=> $b->getOrder());

        foreach ($commands as $handler) {
            if ($handler->isOperOnly()) {
                continue;
            }
            $name = $handler->getName();
            if (isset(self::MODE_DEPENDENT_COMMANDS[$name])) {
                $mode = self::MODE_DEPENDENT_COMMANDS[$name];
                $supported = ['a' => $context->getChannelModeSupport()->hasAdmin(), 'h' => $context->getChannelModeSupport()->hasHalfOp()][$mode] ?? false;
                if (!$supported) {
                    continue;
                }
            }
            $context->replyRaw('  ' . str_pad($name, self::CMD_PAD) . ' ' . $context->trans($handler->getShortDescKey()));
        }

        $context->replyRaw(str_repeat('–', self::HEADER_WIDTH));
        $context->replyRaw($context->trans('help.footer'));
    }
}
