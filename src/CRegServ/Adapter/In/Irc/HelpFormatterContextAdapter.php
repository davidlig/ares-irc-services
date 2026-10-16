<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\In\Irc;

use App\CRegServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\CRegServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\CRegServ\Application\Security\CRegServPermission;

use function str_starts_with;

/**
 * Adapter from CRegServContext to HelpFormatterContextInterface for UnifiedHelpFormatter.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    private const array HELP_GROUPS = [
        ['group_key' => 'help.group.requests', 'commands' => ['REGISTRA', 'ESTADO'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.ircop_group.approval', 'commands' => ['APROBAR', 'DENEGAR'], 'admin' => true, 'subgroup' => true],
        ['group_key' => 'help.ircop_group.queue', 'commands' => ['PENDIENTES', 'DENEGADOS', 'DROP'], 'admin' => true, 'subgroup' => true],
        ['group_key' => 'help.ircop_group.forced', 'commands' => ['SAREGISTER'], 'admin' => true, 'subgroup' => true],
    ];

    public function __construct(
        private CRegServContext $context,
        private CRegServAuthorizationCheckerInterface $authorizationChecker,
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

    /**
     * @return iterable<HelpableCommandInterface>
     */
    public function getCommandsForGeneralHelp(): iterable
    {
        return $this->context->getRegistry()->all();
    }

    public function shouldShowCommandInGeneralHelp(HelpableCommandInterface $command): bool
    {
        $permission = $command instanceof CRegServCommandInterface ? $command->getRequiredPermission() : null;
        if (null !== $permission && self::isCRegServIrcopPermission($permission)) {
            return false;
        }

        if ($command->isOperOnly()) {
            return $this->context->isSenderIrcOperator();
        }

        return true;
    }

    public function canViewCommandInHelp(HelpableCommandInterface $command): bool
    {
        if ($this->shouldShowCommandInGeneralHelp($command)) {
            return true;
        }

        foreach ($this->getIrcopCommands() as $ircopCommand) {
            if ($ircopCommand->getName() === $command->getName()) {
                return true;
            }
        }

        return false;
    }

    public function getHelpGroups(): array
    {
        return self::HELP_GROUPS;
    }

    /**
     * @return iterable<HelpableCommandInterface>
     */
    public function getIrcopCommands(): iterable
    {
        foreach ($this->context->getRegistry()->all() as $command) {
            $permission = $command->getRequiredPermission();
            if (null !== $permission
                && self::isCRegServIrcopPermission($permission)
                && $this->authorizationChecker->isGranted($permission, $this->context)) {
                yield $command;
            }
        }
    }

    public function hasIrcopAccess(): bool
    {
        foreach (CRegServPermission::allIrcop() as $permission) {
            if ($this->authorizationChecker->isGranted($permission, $this->context)) {
                return true;
            }
        }

        return false;
    }

    private static function isCRegServIrcopPermission(string $permission): bool
    {
        return str_starts_with($permission, 'cregserv.');
    }
}
