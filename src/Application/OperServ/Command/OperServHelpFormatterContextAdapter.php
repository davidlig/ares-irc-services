<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command;

use App\Application\Shared\Help\HelpFormatterContextInterface;

final readonly class OperServHelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    public function __construct(
        private OperServContext $context,
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
            return $this->context->isRoot();
        }

        return true;
    }
}
