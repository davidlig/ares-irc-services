<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

use App\MemoServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\MemoServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;

/**
 * Adapter from MemoServContext to HelpFormatterContextInterface for UnifiedHelpFormatter.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    private const array HELP_GROUPS = [
        ['group_key' => 'help.group.messages', 'commands' => ['SEND', 'READ', 'LIST', 'DEL'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.preferences', 'commands' => ['IGNORE', 'ENABLE', 'DISABLE'], 'admin' => false, 'subgroup' => false],
    ];

    public function __construct(
        private MemoServContext $context,
    ) {}

    /**
     * @param array<string, mixed> $params
     */
    public function reply(string $key, array $params = []): void
    {
        $this->context->reply($key, $params);
    }

    public function replyRaw(string $message): void
    {
        $this->context->replyRaw($message);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->context->trans($key, $params);
    }

    public function getCommandsForGeneralHelp(): iterable
    {
        return $this->context->getRegistry()->all();
    }

    public function shouldShowCommandInGeneralHelp(HelpableCommandInterface $command): bool
    {
        return !$command->isOperOnly();
    }

    public function canViewCommandInHelp(HelpableCommandInterface $command): bool
    {
        return $this->shouldShowCommandInGeneralHelp($command);
    }

    public function getHelpGroups(): array
    {
        return self::HELP_GROUPS;
    }

    public function getIrcopCommands(): iterable
    {
        return [];
    }

    public function hasIrcopAccess(): bool
    {
        return false;
    }
}
