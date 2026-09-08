<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

use App\Shared\Application\Help\HelpableCommandInterface;
use App\Shared\Application\Help\HelpFormatterContextInterface;

/**
 * Adapter from MemoServContext to HelpFormatterContextInterface for UnifiedHelpFormatter.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
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

    public function getIrcopCommands(): iterable
    {
        return [];
    }

    public function hasIrcopAccess(): bool
    {
        return false;
    }
}
