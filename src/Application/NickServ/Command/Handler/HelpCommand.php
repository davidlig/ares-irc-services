<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\TimezoneHelpProvider;

use function strlen;

/**
 * HELP [command [sub-option]].
 *
 * Without arguments:   lists all available commands with short descriptions.
 * HELP <command>:      full help for the command, including sub-option table.
 * HELP <cmd> <option>: detailed help for a specific sub-option (e.g. HELP SET PASSWORD).
 * HELP SET TIMEZONE [region]: index of regions (Africa, America, ...) or list of timezones for that region.
 *
 * The registry is obtained from the context to avoid a circular dependency:
 * NickServCommandRegistry → HelpCommand → NickServCommandRegistry.
 */
final readonly class HelpCommand implements NickServCommandInterface
{
    /** Number of visible chars to reserve for command/option names in listings. */
    private const int CMD_PAD = 12;

    private const int SUBS_PAD = 10;

    /** Total visible width used for the decorative header line. */
    private const int HEADER_WIDTH = 40;

    private const int TIMEZONE_LIST_MAX_LINE_LEN = 100;

    public function __construct(
        private readonly TimezoneHelpProvider $timezoneHelpProvider,
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

    public function execute(NickServContext $context): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }

        if (empty($context->args)) {
            $this->showGeneralHelp($context);

            return;
        }

        $targetCmd = strtoupper($context->args[0]);
        $handler = $context->getRegistry()->find($targetCmd);

        if (null === $handler || ($handler->isOperOnly() && !$sender->isOper())) {
            $context->reply('help.unknown_command', ['command' => $targetCmd]);

            return;
        }

        if (isset($context->args[1]) && [] !== $handler->getSubCommandHelp()) {
            $subName = strtoupper($context->args[1]);
            $subCmd = $this->findSubCommand($handler, $subName);

            if (null !== $subCmd) {
                if ('SET' === $handler->getName() && 'TIMEZONE' === $subName) {
                    $regionArg = trim($context->args[2] ?? '');
                    if ('' !== $regionArg) {
                        $this->showTimezoneRegionHelp($context, $regionArg);
                    } else {
                        $this->showTimezoneIndexHelp($context, $handler->getName(), $subCmd);
                    }

                    return;
                }

                $this->showSubCommandHelp($context, $handler->getName(), $subCmd);

                return;
            }
        }

        $this->showCommandHelp($context, $handler);
    }

    private function showGeneralHelp(NickServContext $context): void
    {
        $isOper = $context->sender?->isOper() ?? false;
        $commands = $context->getRegistry()->all();

        usort($commands, static fn ($a, $b) => $a->getOrder() <=> $b->getOrder());

        $this->sendHeader($context, $context->trans('help.header_title'));
        $context->reply('help.intro');
        $context->replyRaw(' ');
        $context->reply('help.general_header');

        foreach ($commands as $command) {
            if ('HELP' === $command->getName()) {
                continue;
            }

            if ($command->isOperOnly() && !$isOper) {
                continue;
            }

            $context->reply('help.command_line', [
                'command' => str_pad($command->getName(), self::CMD_PAD),
                'description' => $context->trans($command->getShortDescKey()),
            ]);
        }

        $context->replyRaw(' ');
        $context->reply('help.general_footer');
        $context->reply('help.footer');
    }

    private function showCommandHelp(NickServContext $context, NickServCommandInterface $handler): void
    {
        $this->sendHeader($context, $handler->getName());
        $context->reply($handler->getHelpKey());

        $subCmds = $handler->getSubCommandHelp();

        if ([] !== $subCmds) {
            $context->replyRaw(' ');
            $context->reply('help.options_header');

            foreach ($subCmds as $sub) {
                $context->reply('help.subcommand_line', [
                    'command' => str_pad($sub['name'], self::SUBS_PAD),
                    'description' => $context->trans($sub['desc_key']),
                ]);
            }

            $context->replyRaw(' ');
            $context->reply('help.set_sub_footer', ['command' => $handler->getName()]);
        }

        $context->replyRaw(' ');
        $context->reply('help.syntax_label', ['syntax' => $context->trans($handler->getSyntaxKey())]);
        $context->reply('help.footer');
    }

    private function showSubCommandHelp(NickServContext $context, string $parentName, array $sub): void
    {
        $this->sendHeader($context, $parentName . ' ' . $sub['name']);
        $context->reply($sub['help_key']);
        if (isset($sub['options_key'])) {
            $context->replyRaw(' ');
            $context->reply($sub['options_key']);
        }
        $context->replyRaw(' ');
        $context->reply('help.syntax_label', ['syntax' => $context->trans($sub['syntax_key'])]);
        $context->reply('help.footer');
    }

    private function showTimezoneIndexHelp(NickServContext $context, string $parentName, array $sub): void
    {
        $this->sendHeader($context, $parentName . ' ' . $sub['name']);
        $context->reply($sub['help_key']);
        $context->replyRaw(' ');
        $context->reply('help.set_timezone.index_label', []);
        $regionsStr = implode(', ', $this->timezoneHelpProvider->getRegions());
        foreach ($this->chunkLine($regionsStr, self::TIMEZONE_LIST_MAX_LINE_LEN, '  ') as $line) {
            $context->replyRaw($line);
        }
        $context->replyRaw(' ');
        $context->reply('help.syntax_label', ['syntax' => $context->trans($sub['syntax_key'])]);
        $context->reply('help.footer');
    }

    /**
     * Splits a comma-separated string into lines of at most $maxLen chars (break at ", ").
     *
     * @return string[]
     */
    private function chunkLine(string $text, int $maxLen, string $linePrefix = ''): array
    {
        $lines = [];
        $current = $linePrefix;
        foreach (explode(', ', $text) as $i => $part) {
            $addition = ($i > 0 ? ', ' : '') . $part;
            if (strlen($current . $addition) > $maxLen && $current !== $linePrefix) {
                $lines[] = $current;
                $current = $linePrefix . $part;
            } else {
                $current .= $addition;
            }
        }
        if ('' !== trim($current)) {
            $lines[] = $current;
        }

        return $lines;
    }

    private function showTimezoneRegionHelp(NickServContext $context, string $regionArg): void
    {
        $region = $this->timezoneHelpProvider->resolveRegion($regionArg)
            ?? $this->timezoneHelpProvider->getRegionForTimezone($regionArg);

        if (null === $region) {
            $this->sendHeader($context, 'SET TIMEZONE ' . $regionArg);
            $context->reply('help.set_timezone.region_unknown', []);
            $context->replyRaw(' ');
            $context->reply('help.footer');

            return;
        }

        $this->sendHeader($context, 'SET TIMEZONE ' . $region);
        $context->reply('help.set_timezone.region_header', ['region' => $region]);
        $timezones = $this->timezoneHelpProvider->getTimezonesForRegion($region);

        $line = '  ';
        foreach ($timezones as $tz) {
            $next = $line . ('  ' === $line ? '' : ', ') . $tz;
            if (strlen($next) > self::TIMEZONE_LIST_MAX_LINE_LEN && '  ' !== $line) {
                $context->replyRaw($line);
                $line = '  ' . $tz;
            } else {
                $line = $next;
            }
        }
        if ('  ' !== $line) {
            $context->replyRaw($line);
        }

        $context->reply('help.footer');
    }

    /** @return array{name: string, desc_key: string, help_key: string, syntax_key: string}|null */
    private function findSubCommand(NickServCommandInterface $handler, string $name): ?array
    {
        foreach ($handler->getSubCommandHelp() as $sub) {
            if (strtoupper($sub['name']) === $name) {
                return $sub;
            }
        }

        return null;
    }

    /**
     * Sends a coloured section header:
     *   \x02\x0307 ℹ TITLE \x0F\x0314─────────────────\x03
     *
     * ℹ (U+2139 INFORMATION SOURCE) replaces the old ■ block.
     * Visible width = 1 (ℹ) + 1 (space) + 1 (space before dashes) + title length + 1 space.
     */
    private function sendHeader(NickServContext $context, string $title): void
    {
        $visible = 4 + mb_strlen($title) + 1;
        $dashes = str_repeat('─', max(0, self::HEADER_WIDTH - $visible));
        $line = "\x02\x0307 ℹ " . $title . " \x0F\x0314" . $dashes . "\x03";
        $context->replyRaw($line);
    }
}
