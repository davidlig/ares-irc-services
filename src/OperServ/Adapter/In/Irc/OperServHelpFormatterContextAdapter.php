<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc;

use App\OperServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\OperServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use InvalidArgumentException;
use Stringable;

use function explode;
use function in_array;
use function is_scalar;
use function is_string;
use function max;
use function mb_strlen;
use function rtrim;
use function str_contains;
use function str_pad;
use function trim;

final readonly class OperServHelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    private const array HELP_GROUPS = [
        ['group_key' => 'help.group.operators_roles', 'commands' => ['IRCOP', 'ROLE'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.network_security', 'commands' => ['GLINE', 'KILL', 'BADMAIL'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.communication', 'commands' => ['MOTD', 'GLOBAL'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.low_level', 'commands' => ['RAW'], 'admin' => false, 'subgroup' => false],
    ];

    public function __construct(private OperServContext $context) {}

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
        if (in_array($key, ['role.operclass.help', 'role.perms.help', 'role.modes.help', 'role.vhost.help'], true)) {
            $params['options_header'] = $this->trans('help.options_header');
            foreach (['LIST', 'VIEW', 'SET', 'ADD', 'DEL', 'CLEAR'] as $option) {
                $params['option_' . $option] = $this->context->renderComponent(Component::CommandRow, '%command%', [
                    '%command%' => new Fragment(str_pad($option, 10), Component::Command),
                ]);
            }
            $params['assigned_status'] = rtrim($this->context->renderComponent(Component::Selected, ' '));
            $params['unassigned_status'] = rtrim($this->context->renderComponent(Component::Unselected, ' '));

            return $this->context->trans($key, $params);
        }
        if ('help.help' === $key) {
            $params['help_command'] = $this->context->renderComponent(Component::Command, $this->context->trans('help.detail_help_command'));
            $params['help_option_command'] = $this->context->renderComponent(Component::Command, $this->context->trans('help.detail_help_option_command'));

            return $this->context->trans($key, $params);
        }
        $component = match ($key) {
            'help.general_header', 'help.ircop_header', 'help.options_header', 'help.examples_header' => Component::Section,
            'help.group_header', 'help.subgroup_header' => Component::Group,
            'help.command_line', 'help.subcommand_line' => Component::CommandRow,
            'help.general_footer', 'help.set_sub_footer' => Component::Navigation,
            'help.syntax_label' => Component::Syntax,
            default => null,
        };
        if ('help.header' === $key) {
            $title = $this->helpValue($params['title'] ?? '');
            $width = max(0, 37 - mb_strlen($title));

            return $this->context->renderComponent(Component::Header, $title) . ' ' . $this->context->separator($width);
        }
        if ('help.footer' === $key) {
            return $this->context->separator(29);
        }
        if (null === $component) {
            return $this->context->trans($key, $params);
        }
        $template = $this->context->trans($key);
        $values = [];
        foreach ($params as $name => $value) {
            $values['%' . trim($name, '%') . '%'] = $this->helpValue($value);
        }
        if (isset($values['%command%']) && Component::CommandRow === $component) {
            $values['%command%'] = new Fragment($values['%command%'], Component::Command);
        }
        if (isset($values['%syntax%']) && is_string($values['%syntax%'])) {
            $values['%syntax%'] = new Fragment($values['%syntax%'], Component::Command);
        }
        if ('help.general_footer' === $key || 'help.set_sub_footer' === $key) {
            $command = $this->context->trans('help.set_sub_footer' === $key ? 'help.option_help_command' : 'help.general_help_command', ['%command%' => $this->helpValue($params['command'] ?? '')]);
            $values['%help_command%'] = new Fragment($command, Component::Command);
        }

        return $this->context->renderComponent($component, $template, $values);
    }

    public function renderExample(string $example): string
    {
        $placeholder = '%command_prefix%';
        if (!str_contains($example, $placeholder)) {
            return $example;
        }
        [$before, $command] = explode($placeholder, $example, 2);

        return $this->context->renderComponent(Component::Example, '%before%%prefix%%command%', [
            '%before%' => $before,
            '%prefix%' => '/msg ' . $this->getBotName() . ' ',
            '%command%' => new Fragment($command, Component::Command),
        ]);
    }

    public function getBotName(): string
    {
        return $this->context->getBotName();
    }

    /** @return iterable<HelpableCommandInterface> */
    public function getCommandsForGeneralHelp(): iterable
    {
        return $this->context->commandRegistry()->all();
    }

    public function shouldShowCommandInGeneralHelp(HelpableCommandInterface $command): bool
    {
        if (!$command instanceof OperServCommandInterface) {
            return false;
        }

        $permission = $command->getRequiredPermission();
        if (null !== $permission) {
            return $this->context->isAuthorized($permission);
        }

        return !$command->isOperOnly() || $this->context->isAuthorized(null);
    }

    public function canViewCommandInHelp(HelpableCommandInterface $command): bool
    {
        return $this->shouldShowCommandInGeneralHelp($command);
    }

    public function getHelpGroups(): array
    {
        return self::HELP_GROUPS;
    }

    /** @return iterable<HelpableCommandInterface> */
    public function getIrcopCommands(): iterable
    {
        return [];
    }

    public function hasIrcopAccess(): bool
    {
        return false;
    }

    private function helpValue(mixed $value): string
    {
        if (null === $value || is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        throw new InvalidArgumentException('HELP parameters must be text.');
    }
}
