<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

use App\ChanServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\ChanServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Security\ChanServPermission;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use InvalidArgumentException;

use function is_string;
use function mb_strlen;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtr;
use function substr;

/**
 * Adapter from ChanServContext to HelpFormatterContextInterface for UnifiedHelpFormatter.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    private const array HELP_GROUPS = [
        ['group_key' => 'help.group.registration_info', 'commands' => ['REGISTER', 'INFO', 'STATUS', 'SET'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.access_levels', 'commands' => ['ACCESS', 'DELACCESS', 'AKICK', 'LEVELS'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.ranks_entry', 'commands' => ['OP', 'DEOP', 'VOICE', 'DEVOICE', 'ADMIN', 'DEADMIN', 'HALFOP', 'DEHALFOP', 'INVITE', 'UNBAN'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.ircop_group.channel_operations', 'commands' => ['CLEARUSERS', 'CLEARACCESS', 'IRCOPONLY'], 'admin' => true, 'subgroup' => true],
        ['group_key' => 'help.ircop_group.channel_management', 'commands' => ['NOEXPIRE', 'SAREGISTER'], 'admin' => true, 'subgroup' => true],
        ['group_key' => 'help.ircop_group.deletion', 'commands' => ['DROP', 'RESTORE'], 'admin' => true, 'subgroup' => true],
        ['group_key' => 'help.ircop_group.restrictions', 'commands' => ['SUSPEND', 'UNSUSPEND', 'FORBID', 'UNFORBID'], 'admin' => true, 'subgroup' => true],
        ['group_key' => 'help.ircop_group.lookup', 'commands' => ['HISTORY', 'LIST'], 'admin' => true, 'subgroup' => true],
        ['group_key' => 'help.ircop_group.registration_approval', 'commands' => ['REGMODE', 'CREG'], 'admin' => true, 'subgroup' => true],
    ];

    /** Commands that require specific mode support to show (name => mode letter). */
    private const array MODE_DEPENDENT_COMMANDS = [
        'ADMIN' => 'a',
        'DEADMIN' => 'a',
        'HALFOP' => 'h',
        'DEHALFOP' => 'h',
    ];

    public function __construct(
        private ChanServContext $context,
        private ChanServOperatorAccess $operatorAccess,
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
        if ('help.header' === $key) {
            return strtr($this->context->trans($key), [
                '%title%' => $this->context->renderTextComponent(Component::Header, self::stringParam($params, 'title')),
                '%separator%' => $this->context->renderSeparator(mb_strlen(self::stringParam($params, 'separator'))),
            ]);
        }
        if ('help.footer' === $key) {
            return $this->context->renderSeparator(29);
        }

        $component = match ($key) {
            'help.general_header', 'help.ircop_header', 'help.options_header', 'help.examples_header' => Component::Section,
            'help.group_header', 'help.subgroup_header' => Component::Group,
            'help.command_line', 'help.subcommand_line' => Component::CommandRow,
            'help.general_footer', 'help.set_sub_footer' => Component::Navigation,
            default => Component::Text,
        };
        if ('help.command_line' === $key || 'help.subcommand_line' === $key) {
            $params['command'] = new Fragment(self::stringParam($params, 'command'), Component::Command);
        } elseif ('help.syntax_label' === $key) {
            $params['syntax'] = new Fragment(self::stringParam($params, 'syntax'), Component::Command);
        } elseif ('help.general_footer' === $key) {
            $params['help'] = new Fragment($this->context->trans('help.general_footer_command'), Component::Command);
        } elseif ('help.set_sub_footer' === $key) {
            $params['help'] = new Fragment($this->context->trans('help.set_sub_footer_command', ['command' => $params['command']]), Component::Command);
        }

        return $this->context->renderComponent($component, $key, $params);
    }

    public function getLanguage(): string
    {
        return $this->context->getLanguage();
    }

    public function renderExample(string $example): string
    {
        if (!str_starts_with($example, '%command_prefix%')) {
            return $example;
        }

        return $this->context->renderTextComponent(Component::Example, '%prefix%%command%', [
            'prefix' => '/msg ' . $this->getBotName() . ' ',
            'command' => new Fragment(substr($example, strlen('%command_prefix%')), Component::Command),
        ]);
    }

    public function getBotName(): string
    {
        return $this->context->getNotifier()->getNick();
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
        $permission = $command instanceof ChanServCommandInterface ? $command->getRequiredPermission() : null;
        if (null !== $permission && self::isChanServIrcopPermission($permission)) {
            return false;
        }

        if ($command->isOperOnly()) {
            return $this->context->sender->isOper ?? false;
        }

        return $this->shouldShowByName($command);
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
        $sender = $this->context->sender;
        $account = $this->context->senderAccount;

        if (null === $sender || null === $account || !$sender->isIdentified) {
            return [];
        }

        return $this->filterByPermission(
            $this->context->getRegistry()->all(),
            strtolower($sender->nick),
            $account->id,
            $sender->isIdentified,
            $sender->isOper,
        );
    }

    public function hasIrcopAccess(): bool
    {
        $sender = $this->context->sender;
        $account = $this->context->senderAccount;

        if (null === $sender || null === $account || !$sender->isIdentified) {
            return false;
        }

        return $this->operatorAccess->hasAnyPermission(
            strtolower($sender->nick),
            $account->id,
            $sender->isIdentified,
            $sender->isOper,
            ChanServPermission::allIrcop(),
        );
    }

    /** @param array<string, mixed> $params */
    private static function stringParam(array $params, string $name): string
    {
        $value = $params[$name] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('HELP presentation parameter must be a string: ' . $name);
        }

        return $value;
    }

    private function shouldShowByName(HelpableCommandInterface $command): bool
    {
        $name = $command->getName();
        if (isset(self::MODE_DEPENDENT_COMMANDS[$name])) {
            $mode = self::MODE_DEPENDENT_COMMANDS[$name];
            $map = [
                'a' => $this->context->getChannelModeSupport()->hasAdmin(),
                'h' => $this->context->getChannelModeSupport()->hasHalfOp(),
            ];

            return $map[$mode];
        }

        return true;
    }

    /**
     * @param iterable<ChanServCommandInterface> $commands
     *
     * @return iterable<HelpableCommandInterface>
     */
    private function filterByPermission(
        iterable $commands,
        string $nickname,
        int $accountId,
        bool $identified,
        bool $ircOperator,
    ): iterable {
        foreach ($commands as $command) {
            $permission = $command->getRequiredPermission();
            if (null !== $permission
                && self::isChanServIrcopPermission($permission)
                && $this->operatorAccess->hasPermission($nickname, $accountId, $identified, $ircOperator, $permission)) {
                yield $command;
            }
        }
    }

    private static function isChanServIrcopPermission(string $permission): bool
    {
        return str_starts_with($permission, 'chanserv.');
    }
}
