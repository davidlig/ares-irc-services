<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Command\OperServHelpFormatterContextAdapter;
use App\Application\Shared\Help\UnifiedHelpFormatter;

final readonly class HelpCommand implements OperServCommandInterface
{
    public function __construct(
        private UnifiedHelpFormatter $formatter,
    ) {
    }

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

    public function execute(OperServContext $context): void
    {
        $sender = $context->getSender();
        if (null !== $sender && !$sender->isOper && !$context->isRoot()) {
            $context->reply('error.oper_only');

            return;
        }

        if (empty($context->args)) {
            $this->showGeneralHelp($context);

            return;
        }

        $targetCmd = strtoupper($context->args[0]);
        $handler = $context->getRegistry()->find($targetCmd);

        if (null === $handler || ($handler->isOperOnly() && !$context->isRoot())) {
            $context->reply('help.unknown_command', ['%command%' => $targetCmd]);

            return;
        }

        $adapter = new OperServHelpFormatterContextAdapter($context);

        if (isset($context->args[1]) && [] !== $handler->getSubCommandHelp()) {
            $subName = strtoupper($context->args[1]);
            $subCmd = $this->findSubCommand($handler, $subName);

            if (null !== $subCmd) {
                $this->formatter->showSubCommandHelp($adapter, $handler->getName(), $subCmd);

                return;
            }
        }

        $this->formatter->showCommandHelp($adapter, $handler);
    }

    private function showGeneralHelp(OperServContext $context): void
    {
        $adapter = new OperServHelpFormatterContextAdapter($context);
        $this->formatter->showGeneralHelp($adapter);
        $context->reply('help.footer');
    }

    /** @return array{name: string, desc_key: string, help_key: string, syntax_key: string}|null */
    private function findSubCommand(OperServCommandInterface $handler, string $name): ?array
    {
        foreach ($handler->getSubCommandHelp() as $sub) {
            if ($name === strtoupper($sub['name'])) {
                return $sub;
            }
        }

        return null;
    }
}
