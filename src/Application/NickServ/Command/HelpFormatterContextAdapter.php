<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command;

use App\Application\Shared\Help\HelpFormatterContextInterface;

/**
 * Adapter from NickServContext to HelpFormatterContextInterface for UnifiedHelpFormatter.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    public function __construct(
        private NickServContext $context,
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
            $isOper = $this->context->sender?->isOper ?? false;

            return $isOper;
        }

        return true;
    }
}
