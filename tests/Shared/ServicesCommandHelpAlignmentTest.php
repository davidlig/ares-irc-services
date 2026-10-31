<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\Kernel;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_key_last;
use function array_unique;
use function assert;
use function count;
use function dirname;
use function implode;
use function sprintf;
use function strtolower;
use function strtoupper;
use function strtr;

/**
 * @phpstan-type AnyServiceCommand NickServCommandInterface|ChanServCommandInterface|MemoServCommandInterface|OperServCommandInterface
 * @phpstan-type AnyServiceRegistry NickServCommandRegistry|ChanServCommandRegistry|MemoServCommandRegistry|OperServCommandRegistry
 */
#[CoversNothing]
final class ServicesCommandHelpAlignmentTest extends TestCase
{
    /** @var list<string> */
    private const array LOCALES = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];

    private static ?Kernel $kernel = null;

    private static ?TranslatorInterface $translator = null;

    /** @var array<string, AnyServiceRegistry> */
    private static array $registries = [];

    public static function setUpBeforeClass(): void
    {
        new Dotenv()->bootEnv(dirname(__DIR__, 2) . '/.env');
        self::$kernel = new Kernel('test', true);
        self::$kernel->boot();

        $container = self::$kernel->getContainer()->get('test.service_container');
        assert($container instanceof ContainerInterface);

        $translator = $container->get('translator');
        assert($translator instanceof TranslatorInterface);
        self::$translator = $translator;

        $nickserv = $container->get(NickServCommandRegistry::class);
        assert($nickserv instanceof NickServCommandRegistry);
        $chanserv = $container->get(ChanServCommandRegistry::class);
        assert($chanserv instanceof ChanServCommandRegistry);
        $memoserv = $container->get(MemoServCommandRegistry::class);
        assert($memoserv instanceof MemoServCommandRegistry);
        $operserv = $container->get(OperServCommandRegistry::class);
        assert($operserv instanceof OperServCommandRegistry);

        self::$registries = [
            'nickserv' => $nickserv,
            'chanserv' => $chanserv,
            'memoserv' => $memoserv,
            'operserv' => $operserv,
        ];
    }

    public static function tearDownAfterClass(): void
    {
        self::$kernel?->shutdown();
        self::$kernel = null;
        self::$translator = null;
        self::$registries = [];
    }

    #[Test]
    public function verifiesAllCommandsExposeValidMetadataAndTranslationsInAllLocales(): void
    {
        self::assertNotNull(self::$translator);

        $missingKeys = [];
        $totalCommands = 0;

        foreach (self::$registries as $domain => $registry) {
            /** @var list<AnyServiceCommand> $commands */
            $commands = $registry->all();
            foreach ($commands as $command) {
                ++$totalCommands;
                $name = $command->getName();

                self::assertNotEmpty($name);

                $keysToCheck = [
                    'short' => $command->getShortDescKey(),
                    'syntax' => $command->getSyntaxKey(),
                    'help' => $command->getHelpKey(),
                ];

                foreach ($keysToCheck as $type => $key) {
                    self::assertNotEmpty($key, sprintf('Empty %s key for %s in %s', $type, $name, $domain));
                    foreach (self::LOCALES as $locale) {
                        $translation = self::$translator->trans($key, [], $domain, $locale);
                        if ($translation === $key) {
                            $missingKeys[] = sprintf('%s: %s [%s] missing in %s', $domain, $name, $key, $locale);
                        }
                    }
                }

                $subcommands = $command->getSubCommandHelp();
                /** @var list<array{name: string, desc_key: string, syntax_key: string, help_key: string}> $subcommands */
                foreach ($subcommands as $sub) {
                    self::assertNotEmpty($sub['name']);
                    self::assertNotEmpty($sub['desc_key']);
                    self::assertNotEmpty($sub['syntax_key']);
                    self::assertNotEmpty($sub['help_key']);

                    $subKeys = [$sub['desc_key'], $sub['syntax_key'], $sub['help_key']];
                    foreach ($subKeys as $subKey) {
                        foreach (self::LOCALES as $locale) {
                            $translation = self::$translator->trans($subKey, [], $domain, $locale);
                            if ($translation === $subKey) {
                                $missingKeys[] = sprintf('%s: %s %s [%s] missing in %s', $domain, $name, $sub['name'], $subKey, $locale);
                            }
                        }
                    }
                }
            }
        }

        self::assertSame([], $missingKeys, sprintf('Missing translation keys found: %s', implode(', ', $missingKeys)));
        self::assertSame(76, $totalCommands, 'Expected exactly 76 registered service commands across all 4 services.');
    }

    #[Test]
    public function verifiesGroupedHelpLabelsExistInEveryLocale(): void
    {
        self::assertNotNull(self::$translator);

        $groupKeys = [
            'nickserv' => [
                'help.group.registration_auth', 'help.group.account_security',
                'help.ircop_group.lookup', 'help.ircop_group.account_management',
                'help.ircop_group.suspensions_bans', 'help.ircop_group.deletion',
            ],
            'chanserv' => [
                'help.group.registration_info', 'help.group.access_levels',
                'help.group.ranks_entry',
                'help.ircop_group.channel_operations', 'help.ircop_group.channel_management',
                'help.ircop_group.deletion', 'help.ircop_group.restrictions', 'help.ircop_group.lookup',
            ],
            'memoserv' => ['help.group.messages', 'help.group.preferences'],
            'operserv' => [
                'help.group.operators_roles', 'help.group.network_security',
                'help.group.communication', 'help.group.low_level',
            ],
        ];

        foreach (self::$registries as $domain => $_registry) {
            foreach (['help.group_header', 'help.subgroup_header', ...$groupKeys[$domain]] as $key) {
                foreach (self::LOCALES as $locale) {
                    self::assertNotSame(
                        $key,
                        self::$translator->trans($key, [], $domain, $locale),
                        sprintf('Missing grouped HELP translation %s in %s (%s).', $key, $domain, $locale),
                    );
                }
            }
        }
    }

    #[Test]
    public function requiresCompleteDefinitionsForEveryRegisteredCommandAndHelpVariant(): void
    {
        $total = 0;
        foreach (self::$registries as $domain => $registry) {
            foreach ($registry->all() as $command) {
                ++$total;
                $definition = $command->getArgumentDefinition();
                self::assertSame($definition->syntaxKey, $command->getSyntaxKey());
                self::assertSame($definition->minimumArgs(), $command->getMinArgs());
                $keys = $this->assertDefinitionMetadata($definition, $domain, $command->getName());
                foreach ($command->getSubCommandHelp() as $help) {
                    self::assertContains($help['syntax_key'], $keys, $domain . ' ' . $command->getName() . ' HELP variant lacks a grammar');
                }
                foreach ([$command->getName(), ...$command->getAliases()] as $name) {
                    self::assertSame($command, $registry->find($name));
                }
            }
        }
        self::assertSame(76, $total);
    }

    #[Test]
    public function detectsMissingRootSyntaxMetadata(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->assertDefinitionMetadata(new CommandArgumentDefinition(''), 'nickserv', 'INVALID');
    }

    #[Test]
    public function detectsMissingDeclaredVariantSyntaxMetadata(): void
    {
        $this->expectException(ExpectationFailedException::class);
        $this->assertDefinitionMetadata(new CommandArgumentDefinition('set.syntax', [new Argument('option')], variants: [
            'PASSWORD' => new CommandArgumentDefinition(''),
        ]), 'nickserv', 'SET');
    }

    #[Test]
    public function verifiesAll16CommandsUseCompactSyntaxInAll14Locales(): void
    {
        self::assertNotNull(self::$translator);

        $expectedPlaceholders = [
            'ca' => ['opt' => '<opció>', 'param' => '[paràmetres]', 'nick' => '<sobrenom>', 'chan' => '<#canal>'],
            'de' => ['opt' => '<Option>', 'param' => '[Parameter]', 'nick' => '<Nickname>', 'chan' => '<#Kanal>'],
            'el' => ['opt' => '<επιλογή>', 'param' => '[παράμετροι]', 'nick' => '<ψευδώνυμο>', 'chan' => '<#κανάλι>'],
            'en' => ['opt' => '<option>', 'param' => '[parameters]', 'nick' => '<nickname>', 'chan' => '<#channel>'],
            'es' => ['opt' => '<opción>', 'param' => '[parámetros]', 'nick' => '<apodo>', 'chan' => '<#canal>'],
            'eu' => ['opt' => '<aukera>', 'param' => '[parametroak]', 'nick' => '<ezizena>', 'chan' => '<#kanala>'],
            'fr' => ['opt' => '<option>', 'param' => '[paramètres]', 'nick' => '<surnom>', 'chan' => '<#canal>'],
            'gl' => ['opt' => '<opción>', 'param' => '[parámetros]', 'nick' => '<alcume>', 'chan' => '<#canle>'],
            'it' => ['opt' => '<opzione>', 'param' => '[parametri]', 'nick' => '<nickname>', 'chan' => '<#canale>'],
            'nl' => ['opt' => '<optie>', 'param' => '[parameters]', 'nick' => '<bijnaam>', 'chan' => '<#kanaal>'],
            'pl' => ['opt' => '<opcja>', 'param' => '[parametry]', 'nick' => '<nick>', 'chan' => '<#kanał>'],
            'pt' => ['opt' => '<opção>', 'param' => '[parâmetros]', 'nick' => '<nickname>', 'chan' => '<#canal>'],
            'ro' => ['opt' => '<opțiune>', 'param' => '[parametri]', 'nick' => '<poreclă>', 'chan' => '<#canal>'],
            'tr' => ['opt' => '<seçenek>', 'param' => '[parametreler]', 'nick' => '<rumuz>', 'chan' => '<#kanal>'],
        ];

        /** @var array<string, array<string, string>> $commandPatterns */
        $commandPatterns = [
            'nickserv' => [
                'SET' => '%cmd% %opt% %param%',
                'FORBIDVHOST' => '%cmd% %opt% %param%',
                'SASET' => '%cmd% %nick% %opt% %param%',
                'HISTORY' => '%cmd% %nick% %opt% %param%',
            ],
            'chanserv' => [
                'SET' => '%cmd% %chan% %opt% %param%',
                'ACCESS' => '%cmd% %chan% %opt% %param%',
                'AKICK' => '%cmd% %chan% %opt% %param%',
                'LEVELS' => '%cmd% %chan% %opt% %param%',
                'HISTORY' => '%cmd% %chan% %opt% %param%',
                'CREG' => '%cmd% %opt% %param%',
            ],
            'memoserv' => [
                'IGNORE' => '%cmd% %opt% %param%',
            ],
            'operserv' => [
                'ROLE' => '%cmd% %opt% %param%',
                'GLINE' => '%cmd% %opt% %param%',
                'BADMAIL' => '%cmd% %opt% %param%',
                'MOTD' => '%cmd% %opt% %param%',
                'IRCOP' => '%cmd% %opt% %param%',
            ],
        ];

        $missingOrInvalid = [];
        $totalChecked = 0;

        foreach ($commandPatterns as $domain => $commands) {
            foreach ($commands as $cmdName => $patternTemplate) {
                $helpSyntaxKey = strtolower($cmdName) . '.help_syntax';
                $syntaxKey = strtolower($cmdName) . '.syntax';

                foreach (self::LOCALES as $locale) {
                    ++$totalChecked;
                    $p = $expectedPlaceholders[$locale];
                    $expected = strtr($patternTemplate, [
                        '%cmd%' => $cmdName,
                        '%opt%' => $p['opt'],
                        '%param%' => $p['param'],
                        '%nick%' => $p['nick'],
                        '%chan%' => $p['chan'],
                    ]);

                    $actual = self::$translator->trans($syntaxKey, [], $domain, $locale);
                    if ($actual === $syntaxKey) {
                        $missingOrInvalid[] = sprintf('%s: %s [%s] missing in %s', $domain, $cmdName, $syntaxKey, $locale);
                        continue;
                    }

                    if ($actual !== $expected) {
                        $missingOrInvalid[] = sprintf('%s: %s [%s] in %s expected "%s" but got "%s"', $domain, $cmdName, $syntaxKey, $locale, $expected, $actual);
                    }

                    // Verify redundant help_syntax key does not exist
                    $helpSyntax = self::$translator->trans($helpSyntaxKey, [], $domain, $locale);
                    self::assertSame($helpSyntaxKey, $helpSyntax, sprintf('%s: redundant [%s] must not exist in %s', $domain, $helpSyntaxKey, $locale));

                    if ('operserv' === $domain && 'ROLE' === $cmdName) {
                        $operclassSyntax = self::$translator->trans('role.syntax_operclass', [], 'operserv', $locale);
                        self::assertSame($expected, $operclassSyntax, sprintf('role.syntax_operclass mismatch in %s', $locale));
                    }
                }
            }
        }

        self::assertSame(16 * 14, $totalChecked, 'Expected exactly 16 commands across 14 locales = 224 checks.');
        self::assertSame([], $missingOrInvalid, sprintf("Failures:\n%s", implode("\n", $missingOrInvalid)));
    }

    #[Test]
    public function verifiesSubcommandHelpPreservesDetailedSyntaxAndValidationIsolation(): void
    {
        self::assertNotNull(self::$translator);

        $commandMap = [
            'nickserv' => ['SET', 'FORBIDVHOST', 'SASET', 'HISTORY'],
            'chanserv' => ['SET', 'ACCESS', 'AKICK', 'LEVELS', 'HISTORY', 'CREG'],
            'memoserv' => ['IGNORE'],
            'operserv' => ['ROLE', 'GLINE', 'BADMAIL', 'MOTD', 'IRCOP'],
        ];

        foreach ($commandMap as $domain => $cmdNames) {
            $registry = self::$registries[$domain];
            foreach ($cmdNames as $cmdName) {
                $cmd = $registry->find($cmdName);
                self::assertNotNull($cmd, "Command $cmdName not found in $domain registry");

                // Validation isolation: getSyntaxKey() must be the argument definition syntaxKey
                $definition = $cmd->getArgumentDefinition();
                self::assertSame($definition->syntaxKey, $cmd->getSyntaxKey(), "Validation definition mismatch for $domain $cmdName");
                self::assertStringEndsWith('.syntax', $cmd->getSyntaxKey());
                self::assertStringNotContainsString('.help_syntax', $cmd->getSyntaxKey());

                // Detailed subhelp verification: each subcommand retains its specific syntax
                $subcommands = $cmd->getSubCommandHelp();
                self::assertNotEmpty($subcommands, "$domain $cmdName should have subcommands");
                foreach ($subcommands as $sub) {
                    self::assertStringEndsWith('.syntax', $sub['syntax_key']);
                    self::assertStringNotContainsString('.help_syntax', $sub['syntax_key']);
                    foreach (self::LOCALES as $locale) {
                        $subSyntax = self::$translator->trans($sub['syntax_key'], [], $domain, $locale);
                        self::assertNotSame($sub['syntax_key'], $subSyntax, "Missing sub syntax for {$sub['syntax_key']} in $locale");
                        // Detailed subhelp syntax must start with the literal command or command+target
                        self::assertStringStartsWith($cmdName, $subSyntax, "Subcommand syntax {$sub['syntax_key']} in $locale should start with $cmdName");
                    }
                }
            }
        }
    }

    /** @return list<string> */
    private function assertDefinitionMetadata(CommandArgumentDefinition $definition, string $domain, string $path): array
    {
        self::assertNotEmpty($definition->syntaxKey, $domain . ' ' . $path . ' lacks syntax metadata');
        self::assertNotNull(self::$translator);
        foreach (self::LOCALES as $locale) {
            self::assertNotSame($definition->syntaxKey, self::$translator->trans($definition->syntaxKey, [], $domain, $locale), $path . ' lacks syntax in ' . $locale);
        }
        $keys = [$definition->syntaxKey];
        $names = [];
        foreach ($definition->arguments as $index => $argument) {
            self::assertNotEmpty($argument->name, $path . ' has an unnamed argument');
            self::assertNotContains($argument->name, $names, $path . ' repeats an argument name');
            $names[] = $argument->name;
            if ($argument->rest) {
                self::assertSame(array_key_last($definition->arguments), $index, $path . ' consumes rest before the last argument');
            }
            if (null !== $argument->prefix) {
                self::assertNotSame('', $argument->prefix, $path . ' has an empty conditional prefix');
            }
        }
        foreach ($definition->variants as $name => $variant) {
            self::assertNotEmpty($definition->arguments, $path . ' has no variant selector');
            self::assertSame(strtoupper($name), $name, $path . ' has a noncanonical selector');
            self::assertFalse($definition->arguments[array_key_last($definition->arguments)]->rest, $path . ' selects a variant after consuming rest');
            $keys = [...$keys, ...$this->assertDefinitionMetadata($variant, $domain, $path . ' ' . $name)];
        }
        if (null !== $definition->unknownVariant) {
            $keys = [...$keys, ...$this->assertDefinitionMetadata($definition->unknownVariant, $domain, $path . ' <unknown>')];
        }
        foreach ($definition->alternativeOrders as $order) {
            self::assertNotEmpty($order, $path . ' declares an empty legacy layout');
            self::assertCount(count($order), array_unique($order), $path . ' duplicates a legacy token');
            foreach ($order as $index) {
                self::assertGreaterThanOrEqual(0, $index, $path . ' has a negative legacy position');
            }
        }

        return $keys;
    }
}
