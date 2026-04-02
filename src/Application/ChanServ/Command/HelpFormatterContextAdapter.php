<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command;

use App\Application\Shared\Help\HelpFormatterContextInterface;

/**
 * Adapter from ChanServContext to HelpFormatterContextInterface for UnifiedHelpFormatter.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    /** Commands that require specific mode support to show (name => mode letter). */
    private const array MODE_DEPENDENT_COMMANDS = [
        'ADMIN' => 'a',
        'DEADMIN' => 'a',
        'HALFOP' => 'h',
        'DEHALFOP' => 'h',
    ];

    public function __construct(
        private ChanServContext $context,
    ) {
    }

    public function reply(string $key, array $params = []): void
    {
        $this->context->reply($key, $params);
    }

    public function replyRaw(string $message): void
    {
        $this->context->replyRaw($message);
    }

    public function trans(string $key, array $params = []): string
    {
        return $this->context->trans($key, $params);
    }

    public function getCommandsForGeneralHelp(): iterable
    {
        return $this->context->getRegistry()->all();
    }

    public function shouldShowCommandInGeneralHelp(object $command): bool
    {
        if ($command->isOperOnly()) {
            return false;
        }

        $name = $command->getName();
        if (isset(self::MODE_DEPENDENT_COMMANDS[$name])) {
            $mode = self::MODE_DEPENDENT_COMMANDS[$name];
            $supported = ['a' => $this->context->getChannelModeSupport()->hasAdmin(), 'h' => $this->context->getChannelModeSupport()->hasHalfOp()][$mode] ?? false;

            return $supported;
        }

        return true;
    }

    public function getIrcopCommands(): iterable
    {
        // ChanServ does not have IRCop commands yet
        return [];
    }

    public function hasIrcopAccess(): bool
    {
        // ChanServ does not have IRCop commands yet
        return false;
    }
}
