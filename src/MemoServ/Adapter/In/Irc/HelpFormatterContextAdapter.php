<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

use App\MemoServ\Adapter\In\Irc\Help\HelpableCommandInterface;
use App\MemoServ\Adapter\In\Irc\Help\HelpFormatterContextInterface;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use InvalidArgumentException;
use Stringable;

use function explode;
use function is_scalar;
use function is_string;
use function str_contains;
use function trim;

/**
 * Adapter from MemoServContext to HelpFormatterContextInterface for UnifiedHelpFormatter.
 */
final readonly class HelpFormatterContextAdapter implements HelpFormatterContextInterface
{
    private const array HELP_GROUPS = [
        ['group_key' => 'help.group.messages', 'commands' => ['SEND', 'READ', 'LIST', 'DEL'], 'admin' => false, 'subgroup' => false],
        ['group_key' => 'help.group.preferences', 'commands' => ['IGNORE', 'ENABLE', 'DISABLE'], 'admin' => false, 'subgroup' => false],
    ];

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
            $width = 29;

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

        return $this->context->renderComponent(Component::MarkedExample, '%before%%prefix%%command%', [
            '%before%' => $before,
            '%prefix%' => '/msg ' . $this->getBotName() . ' ',
            '%command%' => new Fragment($command, Component::Command),
        ]);
    }

    public function getBotName(): string
    {
        return $this->context->getNotifier()->getNick();
    }

    public function getCommandsForGeneralHelp(): iterable
    {
        return $this->context->getRegistry()->all();
    }

    public function shouldShowCommandInGeneralHelp(HelpableCommandInterface $command): bool
    {
        return !$command->isOperOnly();
    }

    public function canViewCommandInHelp(HelpableCommandInterface $command): bool
    {
        return $this->shouldShowCommandInGeneralHelp($command);
    }

    public function getHelpGroups(): array
    {
        return self::HELP_GROUPS;
    }

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
