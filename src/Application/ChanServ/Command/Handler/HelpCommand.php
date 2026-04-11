<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\HelpFormatterContextAdapter;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Security\PermissionRegistry;
use App\Application\Shared\Help\UnifiedHelpFormatter;

/**
 * HELP [command [sub-option]].
 *
 * Lists commands (filtered by IRCd mode support and IRCop permission) or shows help for a command.
 * Design aligned with NickServ via UnifiedHelpFormatter.
 */
final readonly class HelpCommand implements ChanServCommandInterface
{
    public function __construct(
        private UnifiedHelpFormatter $formatter,
        private IrcopAccessHelper $accessHelper,
        private RootUserRegistry $rootRegistry,
        private PermissionRegistry $permissionRegistry,
        private readonly int $inactivityExpiryDays = 0,
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

    public function allowsSuspendedChannel(): bool
    {
        return true;
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
            $context->reply('help.unknown_command', ['command' => $targetCmd]);

            return;
        }

        if (isset($context->args[1]) && [] !== $handler->getSubCommandHelp()) {
            $subName = strtoupper($context->args[1]);
            $subCmd = $this->findSubCommand($handler, $subName);

            if (null !== $subCmd) {
                $adapter = new HelpFormatterContextAdapter($context, $this->accessHelper, $this->rootRegistry, $this->permissionRegistry);
                $this->formatter->showSubCommandHelp($adapter, $handler->getName(), $subCmd);

                return;
            }
        }

        $adapter = new HelpFormatterContextAdapter($context, $this->accessHelper, $this->rootRegistry, $this->permissionRegistry);
        $this->formatter->showCommandHelp($adapter, $handler);
    }

    private function showGeneralHelp(ChanServContext $context): void
    {
        $adapter = new HelpFormatterContextAdapter($context, $this->accessHelper, $this->rootRegistry, $this->permissionRegistry);
        $this->formatter->showGeneralHelp($adapter);
        if ($this->inactivityExpiryDays > 0) {
            $context->replyRaw(' ');
            $context->reply('help.intro_expiration', ['%days%' => $this->inactivityExpiryDays]);
        }
        $context->reply('help.footer');
    }

    /** @return array{name: string, desc_key: string, help_key: string, syntax_key: string}|null */
    private function findSubCommand(ChanServCommandInterface $handler, string $name): ?array
    {
        foreach ($handler->getSubCommandHelp() as $sub) {
            if ($name === strtoupper($sub['name'])) {
                return $sub;
            }
        }

        return null;
    }
}
