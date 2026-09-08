<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc\Command;

use App\MemoServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\Shared\Application\Help\UnifiedHelpFormatter;

use function array_find;
use function strtoupper;

/**
 * HELP [command [sub-option]].
 * Design aligned with NickServ/ChanServ via UnifiedHelpFormatter.
 */
final readonly class HelpCommand implements MemoServCommandInterface
{
    public function __construct(
        private UnifiedHelpFormatter $formatter,
    ) {}

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

    public function execute(MemoServContext $context): void
    {
        if (empty($context->args)) {
            $adapter = new HelpFormatterContextAdapter($context);
            $this->formatter->showGeneralHelp($adapter);
            $context->reply('help.footer');

            return;
        }

        $targetCmd = strtoupper($context->args[0]);
        $handler = $context->getRegistry()->find($targetCmd);

        if (null === $handler) {
            $context->reply('help.unknown_command', ['command' => $targetCmd]);

            return;
        }

        if (isset($context->args[1]) && [] !== $handler->getSubCommandHelp()) {
            $subName = strtoupper($context->args[1]);
            $subCmd = $this->findSubCommand($handler, $subName);

            if (null !== $subCmd) {
                $adapter = new HelpFormatterContextAdapter($context);
                $this->formatter->showSubCommandHelp($adapter, $handler->getName(), $subCmd);

                return;
            }
        }

        $adapter = new HelpFormatterContextAdapter($context);
        $this->formatter->showCommandHelp($adapter, $handler);
    }

    /**
     * @return array{name: string, desc_key: string, help_key: string, syntax_key: string}|null
     */
    private function findSubCommand(MemoServCommandInterface $handler, string $subName): ?array
    {
        return array_find($handler->getSubCommandHelp(), static fn (array $sub): bool => $sub['name'] === $subName);
    }
}
