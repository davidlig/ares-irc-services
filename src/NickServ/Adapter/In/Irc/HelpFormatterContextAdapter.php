<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc;

use App\NickServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\NickServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Security\NickServPermission;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function is_string;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

/**
 * Adapter that exposes HelpFormatterContextInterface from NickServContext.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    private const array HELP_GROUPS = [
        ['group_key' => 'help.group.registration_auth', 'commands' => ['REGISTER', 'IDENTIFY', 'VERIFY', 'RESEND', 'RECOVER'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.account_security', 'commands' => ['STATUS', 'INFO', 'SET'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.ircop_group.lookup', 'commands' => ['USERIP', 'WHOIP', 'LIST', 'HISTORY'], 'admin' => true, 'subgroup' => true],
        ['group_key' => 'help.ircop_group.account_management', 'commands' => ['SAREGISTER', 'SASET', 'RENAME', 'NOEXPIRE', 'REGMODE'], 'admin' => true, 'subgroup' => true],
        ['group_key' => 'help.ircop_group.suspensions_bans', 'commands' => ['SUSPEND', 'UNSUSPEND', 'FORBID', 'UNFORBID', 'FORBIDVHOST'], 'admin' => true, 'subgroup' => true],
        ['group_key' => 'help.ircop_group.deletion', 'commands' => ['DROP', 'RESTORE'], 'admin' => true, 'subgroup' => true],
    ];

    public function __construct(
        private NickServContext $context,
        private NickServOperatorAccess $operatorAccess,
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
        $inline = [
            'identify.help' => 1, 'identify.native_help' => 2, 'set.language.help' => 14, 'set.private.help' => 2,
            'set.msg.help' => 4, 'set.vhost.help' => 1, 'regmode.help' => 3,
        ];
        if (isset($inline[$key])) {
            for ($i = 0; $i < $inline[$key]; ++$i) {
                $fragmentKey = 'help_fragments.' . str_replace('.', '_', $key) . '_' . $i;
                $fragmentComponent = match ($key) {
                    'identify.native_help' => Component::Command,
                    'set.language.help' => Component::IndentedEmphasis,
                    'regmode.help' => Component::BulletedRow,
                    default => Component::Emphasis,
                };
                $text = $this->context->trans($fragmentKey);
                $params['emphasis_' . $i] = $this->context->hasHelpPresentation() ? new Fragment($text, $fragmentComponent) : $text;
            }

            return $this->context->formatHelp(Component::Text, $key, $params);
        }

        if ('suspend.help' === $key) {
            for ($i = 0; $i < 4; ++$i) {
                $text = $this->context->trans('help_fragments.suspend_help_' . $i);
                $params['emphasis_' . $i] = $this->context->hasHelpPresentation() ? new Fragment($text, Component::Command) : $text;
            }

            return $this->context->formatHelp(Component::Text, $key, $params);
        }

        if (!$this->context->hasHelpPresentation()) {
            return $this->context->trans($key, $params);
        }

        if ('help.set_timezone.region_unknown' === $key) {
            if (isset($params['syntax']) && is_string($params['syntax'])) {
                $params['syntax'] = new Fragment($params['syntax'], Component::Command);
            }

            return $this->context->formatHelpRole(Role::Error, $key, $params);
        }
        if ('help.header' === $key) {
            return $this->context->formatHelp(Component::Header, $key, $params) . ' ' . $this->context->helpSeparator();
        }
        if ('help.footer' === $key) {
            return $this->context->helpSeparator();
        }
        $component = match ($key) {
            'help.general_header', 'help.options_header', 'help.examples_header', 'help.ircop_header', 'help.set_timezone.region_header' => Component::Section,
            'help.group_header', 'help.subgroup_header' => Component::Group,
            'help.command_line', 'help.subcommand_line' => Component::CommandRow,
            'help.general_footer', 'help.set_sub_footer' => Component::Navigation,
            'help.syntax_label', 'help.set_timezone.index_label' => Component::Syntax,
            default => null,
        };
        if (null === $component) {
            return $this->context->trans($key, $params);
        }
        if (isset($params['command']) && is_string($params['command']) && Component::CommandRow === $component) {
            $params['command'] = new Fragment($params['command'], Component::Command);
        }
        if (isset($params['syntax']) && is_string($params['syntax'])) {
            $params['syntax'] = new Fragment($params['syntax'], Component::Command);
        }

        return $this->context->formatHelp($component, $key, $params);
    }

    public function renderExpiration(int $days): string
    {
        $duration = $this->context->trans('help.intro_expiration_duration', ['days' => $days]);

        return $this->context->formatHelp(Component::ExpirationNotice, 'help.intro_expiration', [
            'label' => $this->context->trans('help.intro_expiration_label'),
            'duration' => new Fragment($duration, Component::Expiration),
        ]);
    }

    public function usesHelpPresentation(): bool
    {
        return $this->context->hasHelpPresentation();
    }

    public function renderExample(string $example): string
    {
        $command = substr($example, strlen('%command_prefix%'));

        return $this->context->formatHelpTemplate(Component::Example, '%prefix%%command%', [
            'prefix' => '/msg ' . $this->getBotName() . ' ',
            'command' => new Fragment($command, Component::Command),
        ]);
    }

    public function renderNativeExample(string $example): string
    {
        return $this->context->formatHelpTemplate(Component::Example, '%command%', [
            'command' => new Fragment($example, Component::Command),
        ]);
    }

    public function getBotName(): string
    {
        return $this->context->getNotifier()->getNick();
    }

    public function formatDuration(int $seconds): string
    {
        return $this->context->formatDuration($seconds);
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
        // Commands with IRCop permissions are not shown in general help
        $permission = $command instanceof NickServCommandInterface ? $command->getRequiredPermission() : null;
        if (null !== $permission && self::isNickServIrcopPermission($permission)) {
            return false;
        }

        // Legacy: isOperOnly() commands shown only to opers
        if ($command->isOperOnly()) {
            return $this->context->sender->isOper ?? false;
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
        $sender = $this->context->sender;
        $account = $this->context->senderAccount;

        if (null === $sender || null === $account || !$sender->isIdentified) {
            return [];
        }

        return $this->filterByPermission(
            $this->context->getRegistry()->all(),
            strtolower($sender->nick),
            (int) $account->getId(),
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
            (int) $account->getId(),
            $sender->isIdentified,
            $sender->isOper,
            NickServPermission::allIrcop(),
        );
    }

    /**
     * @param iterable<NickServCommandInterface> $commands
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
                && self::isNickServIrcopPermission($permission)
                && $this->operatorAccess->hasPermission($nickname, $accountId, $identified, $ircOperator, $permission)) {
                yield $command;
            }
        }
    }

    private static function isNickServIrcopPermission(string $permission): bool
    {
        return str_starts_with($permission, 'nickserv.');
    }
}
