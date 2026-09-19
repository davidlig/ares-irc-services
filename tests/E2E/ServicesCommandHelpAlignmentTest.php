<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\Kernel;
use App\MemoServ\Adapter\In\Irc\MemoServCommandInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function assert;
use function dirname;
use function explode;
use function implode;
use function in_array;
use function preg_match;
use function sprintf;
use function strtoupper;
use function trim;

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
        self::assertSame(64, $totalCommands, 'Expected exactly 64 registered service commands across all 4 services.');
    }

    #[Test]
    public function detectsDiscrepanciesBetweenHelpSyntaxAndSubcommands(): void
    {
        self::assertNotNull(self::$translator);

        $discrepancies = [];

        foreach (self::$registries as $domain => $registry) {
            /** @var list<AnyServiceCommand> $commands */
            $commands = $registry->all();
            foreach ($commands as $command) {
                $name = $command->getName();
                $syntaxEn = self::$translator->trans($command->getSyntaxKey(), [], $domain, 'en');

                $subcommands = $command->getSubCommandHelp();
                /** @var list<string> $subNames */
                $subNames = array_column($subcommands, 'name');

                if (preg_match('/\{([^}]+)\}/', $syntaxEn, $matches)) {
                    $choices = explode('|', $matches[1]);
                    foreach ($choices as $choice) {
                        $choiceUpper = strtoupper(trim($choice));
                        if ('#' === $choiceUpper[0] || 'NICKNAME' === $choiceUpper || 'SERVICE' === $choiceUpper || 'PRIVMSG' === $choiceUpper || 'NOTICE' === $choiceUpper || 'ON' === $choiceUpper || 'OFF' === $choiceUpper || 'ALL' === $choiceUpper) {
                            continue;
                        }
                        if ([] !== $subNames && !in_array($choiceUpper, $subNames, true)) {
                            $discrepancies[] = sprintf(
                                '[%s %s] Syntax advertises choice {%s} which is NOT in getSubCommandHelp() [%s]',
                                $domain,
                                $name,
                                $choiceUpper,
                                implode(', ', $subNames),
                            );
                        }
                    }
                }
            }
        }

        self::assertSame([], $discrepancies, sprintf('Discrepancies found between help syntax and subcommands: %s', implode(', ', $discrepancies)));
    }
}
