<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\HelpFormatterContextAdapter;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\TimezoneHelpProvider;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Security\PermissionRegistry;
use App\Application\Shared\Help\UnifiedHelpFormatter;

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
    private const int TIMEZONE_LIST_MAX_LINE_LEN = 100;

    public function __construct(
        private readonly UnifiedHelpFormatter $formatter,
        private readonly TimezoneHelpProvider $timezoneHelpProvider,
        private readonly IrcopAccessHelper $accessHelper,
        private readonly RootUserRegistry $rootRegistry,
        private readonly PermissionRegistry $permissionRegistry,
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

        if (null === $handler || ($handler->isOperOnly() && !$sender->isOper)) {
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

                $adapter = $this->createAdapter($context);
                $this->formatter->showSubCommandHelp($adapter, $handler->getName(), $subCmd);

                return;
            }
        }

        $adapter = $this->createAdapter($context);
        $this->formatter->showCommandHelp($adapter, $handler);
    }

    private function showGeneralHelp(NickServContext $context): void
    {
        $adapter = $this->createAdapter($context);
        $this->formatter->showGeneralHelp($adapter);
        if ($this->inactivityExpiryDays > 0) {
            $context->replyRaw(' ');
            $context->reply('help.intro_expiration', ['%days%' => $this->inactivityExpiryDays]);
        }
        $context->reply('help.footer');
    }

    private function createAdapter(NickServContext $context): HelpFormatterContextAdapter
    {
        return new HelpFormatterContextAdapter(
            $context,
            $this->accessHelper,
            $this->rootRegistry,
            $this->permissionRegistry,
        );
    }

    private function showTimezoneIndexHelp(NickServContext $context, string $parentName, array $sub): void
    {
        $adapter = $this->createAdapter($context);
        $this->formatter->sendHeader($adapter, $parentName . ' ' . $sub['name']);
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

        $adapter = $this->createAdapter($context);

        if (null === $region) {
            $this->formatter->sendHeader($adapter, 'SET TIMEZONE ' . $regionArg);
            $context->reply('help.set_timezone.region_unknown', []);
            $context->replyRaw(' ');
            $context->reply('help.footer');

            return;
        }

        $this->formatter->sendHeader($adapter, 'SET TIMEZONE ' . $region);
        $context->reply('help.set_timezone.region_header', ['region' => $region]);
        $timezones = $this->timezoneHelpProvider->getTimezonesForRegion($region);
        foreach ($this->chunkLine(implode(', ', $timezones), self::TIMEZONE_LIST_MAX_LINE_LEN, '  ') as $line) {
            $context->replyRaw($line);
        }
        $context->replyRaw(' ');
        $context->reply('help.footer');
    }

    /** @return array{name: string, desc_key: string, help_key: string, syntax_key: string}|null */
    private function findSubCommand(NickServCommandInterface $handler, string $name): ?array
    {
        foreach ($handler->getSubCommandHelp() as $sub) {
            if ($name === strtoupper($sub['name'])) {
                return $sub;
            }
        }

        return null;
    }
}
