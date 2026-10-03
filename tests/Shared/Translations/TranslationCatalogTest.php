<?php

declare(strict_types=1);

namespace App\Tests\Shared\Translations;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;

use function array_flip;
use function array_keys;
use function array_unique;
use function array_values;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function preg_match;
use function preg_match_all;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;

use const PREG_SET_ORDER;

/**
 * Guards the translation catalog contract: every locale exposes the same keys,
 * every code-referenced key exists, no dead keys, and placeholders stay aligned.
 */
#[CoversNothing]
final class TranslationCatalogTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../..';

    /** @var list<string> */
    private const array DOMAINS = ['chanserv', 'nickserv', 'memoserv', 'operserv', 'common', 'mail'];

    /** @var list<string> */
    private const array LOCALES = ['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'];

    /** @var array<string, string> service directory => translation domain */
    private const array SERVICE_DIRS = [
        'ChanServ' => 'chanserv',
        'NickServ' => 'nickserv',
        'MemoServ' => 'memoserv',
        'OperServ' => 'operserv',
    ];

    /** @var list<string> */
    private const array DYNAMIC_KEY_PATTERNS = [
        '/^set\\.(password|email|language|timezone|private|msg|vhost)\\.(short|help|syntax)$/',
        '/^saset\\.(password|email|language|timezone|private|msg|vhost)\\.(short|help|syntax)$/',
        '/^history\\.extra\\.[a-z_]+$/',
        '/^(op|deop|voice|devoice|halfop|dehalfop|admin|deadmin)\\.(done|notice_grant)$/',
        '/^(admin|halfop|op|voice)\\.(not_supported|user_not_on_channel)$/',
        '/^ignore\\.[a-z_]+\\.(syntax|short|help)$/',
    ];

    #[Test]
    public function serviceHelpIntroductionsUseConciseLocalizedDescriptionsInEveryLocale(): void
    {
        $introductions = [
            'ca' => [
                'nickserv' => 'Gestió i protecció de sobrenoms.',
                'operserv' => 'Servei de gestió de xarxa per a operadors.',
                'chanserv' => 'Servei de registre i gestió de canals.',
            ],
            'de' => [
                'nickserv' => 'Verwaltung und Schutz von Nicknames.',
                'operserv' => 'Netzwerkverwaltungsdienst für Operatoren.',
                'chanserv' => 'Channel-Registrierungs- und Verwaltungsdienst.',
            ],
            'el' => [
                'nickserv' => 'Διαχείριση και προστασία ψευδωνύμων.',
                'operserv' => 'Υπηρεσία διαχείρισης δικτύου για χειριστές.',
                'chanserv' => 'Υπηρεσία καταχώρησης και διαχείρισης καναλιών.',
            ],
            'en' => [
                'nickserv' => 'Nickname management and protection.',
                'operserv' => 'Network management service for operators.',
                'chanserv' => 'Channel registration and management service.',
            ],
            'es' => [
                'nickserv' => 'Gestión y protección de apodos.',
                'operserv' => 'Servicio de gestión de red para operadores.',
                'chanserv' => 'Servicio de registro y gestión de canales.',
            ],
            'eu' => [
                'nickserv' => 'Ezizenen kudeaketa eta babesa.',
                'operserv' => 'Operadoreentzako sare kudeaketa zerbitzua.',
                'chanserv' => 'Kanalak erregistratzeko eta kudeatzeko zerbitzua.',
            ],
            'fr' => [
                'nickserv' => 'Gestion et protection des surnoms.',
                'operserv' => 'Service de gestion du réseau pour les opérateurs.',
                'chanserv' => 'Service d\'enregistrement et de gestion des canaux.',
            ],
            'gl' => [
                'nickserv' => 'Xestión e protección de alcumes.',
                'operserv' => 'Servizo de xestión da rede para operadores.',
                'chanserv' => 'Servizo de rexistro e xestión de canles.',
            ],
            'it' => [
                'nickserv' => 'Gestione e protezione dei nickname.',
                'operserv' => 'Servizio di gestione della rete per operatori.',
                'chanserv' => 'Servizio di registrazione e gestione dei canali.',
            ],
            'nl' => [
                'nickserv' => 'Beheer en bescherming van bijnamen.',
                'operserv' => 'Netwerkbeheerservice voor operators.',
                'chanserv' => 'Kanaalregistratie- en beheerservice.',
            ],
            'pl' => [
                'nickserv' => 'Zarządzanie nickami i ich ochrona.',
                'operserv' => 'Usługa zarządzania siecią dla operatorów.',
                'chanserv' => 'Usługa rejestracji i zarządzania kanałami.',
            ],
            'pt' => [
                'nickserv' => 'Gestão e proteção de nicknames.',
                'operserv' => 'Serviço de gestão da rede para operadores.',
                'chanserv' => 'Serviço de registo e gestão de canais.',
            ],
            'ro' => [
                'nickserv' => 'Gestionarea și protecția poreclelor.',
                'operserv' => 'Serviciu de administrare a rețelei pentru operatori.',
                'chanserv' => 'Serviciu de înregistrare și administrare a canalelor.',
            ],
            'tr' => [
                'nickserv' => 'Rumuz yönetimi ve koruması.',
                'operserv' => 'Operatörler için ağ yönetim hizmeti.',
                'chanserv' => 'Kanal kaydı ve yönetim hizmeti.',
            ],
        ];

        foreach (self::LOCALES as $locale) {
            foreach ($introductions[$locale] as $domain => $expected) {
                $catalog = self::flatten(self::parseDomain($domain, $locale));

                self::assertSame($expected, $catalog['help.intro'] ?? null, $domain . '.' . $locale . ': help.intro');
            }
        }
    }

    #[Test]
    public function allLocalesDefineTheSameKeysPerDomain(): void
    {
        foreach (self::DOMAINS as $domain) {
            $expected = array_keys(self::flatten(self::parseDomain($domain, 'en')));
            sort($expected);

            foreach (self::LOCALES as $locale) {
                $actual = array_keys(self::flatten(self::parseDomain($domain, $locale)));
                sort($actual);

                self::assertSame(
                    $expected,
                    $actual,
                    sprintf('Key mismatch between en and %s in domain "%s".', $locale, $domain),
                );
            }
        }
    }

    #[Test]
    public function everyKeyReferencedByCodeIsDefined(): void
    {
        $catalog = [];
        $union = [];
        foreach (self::DOMAINS as $domain) {
            $catalog[$domain] = array_flip(array_keys(self::flatten(self::parseDomain($domain, 'en'))));
            foreach ($catalog[$domain] as $key => $unused) {
                $union[$key] = true;
            }
        }

        $missing = [];

        foreach (self::phpFiles(self::ROOT . '/src') as $file) {
            $relative = str_replace(self::ROOT . '/', '', $file);
            $contents = (string) file_get_contents($file);
            $service = self::serviceFor($relative);

            /** @var array<int, array<int, string>> $matches */
            if (null !== $service && preg_match_all("/->reply\\(\\s*'([^']+)'/", $contents, $matches)) {
                foreach ($matches[1] as $key) {
                    self::collectMissing($key, $catalog[$service], $union, false, $relative, $missing);
                }
            }

            if (null !== $service && preg_match_all("/->trans\\(\\s*'([^']+)'/", $contents, $matches)) {
                foreach ($matches[1] as $key) {
                    self::collectMissing($key, $catalog[$service], $union, true, $relative, $missing);
                }
            }

            /** @var array<int, array{0: string, 1: string, 2: string}> $domainMatches */
            if (preg_match_all(
                "/->trans\\(\\s*'([^']+)'\\s*,[^;]*?'(chanserv|nickserv|memoserv|operserv|common|mail)'/s",
                $contents,
                $domainMatches,
                PREG_SET_ORDER,
            )) {
                foreach ($domainMatches as $match) {
                    self::collectMissing($match[1], $catalog[$match[2]], $union, true, $relative, $missing);
                }
            }

            if (null === $service) {
                continue;
            }

            /** @var array<int, array<int, string>> $matches */
            if (preg_match_all(
                "/function get(?:Syntax|Help|ShortDesc)Key\\([^)]*\\)\\s*:\\s*string\\s*\\{\\s*return\\s*'([^']+)';/s",
                $contents,
                $matches,
            )) {
                foreach ($matches[1] as $key) {
                    self::collectMissing($key, $catalog[$service], $union, false, $relative, $missing);
                }
            }

            /** @var array<int, array<int, string>> $matches */
            if (preg_match_all("/'(?:help_key|syntax_key|desc_key)'\\s*=>\\s*'([^']+)'/", $contents, $matches)) {
                foreach ($matches[1] as $key) {
                    self::collectMissing($key, $catalog[$service], $union, false, $relative, $missing);
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            "Referenced translation keys missing from their domain:\n" . implode("\n", $missing),
        );
    }

    #[Test]
    public function everyDefinedKeyIsReferencedOrDynamic(): void
    {
        $source = '';
        foreach (self::phpFiles(self::ROOT . '/src') as $file) {
            $source .= (string) file_get_contents($file) . "\n";
        }

        $dead = [];
        foreach (self::DOMAINS as $domain) {
            foreach (array_keys(self::flatten(self::parseDomain($domain, 'en'))) as $key) {
                if (str_contains($source, $key) || self::isDynamicKey($key)) {
                    continue;
                }
                $dead[] = $domain . '.' . $key;
            }
        }

        self::assertSame(
            [],
            $dead,
            "Translation keys defined but never referenced by code:\n" . implode("\n", $dead),
        );
    }

    #[Test]
    public function placeholdersMatchAcrossLocales(): void
    {
        $mismatches = [];

        foreach (self::DOMAINS as $domain) {
            $english = self::flatten(self::parseDomain($domain, 'en'));
            $values = [];
            foreach (self::LOCALES as $locale) {
                $values[$locale] = self::flatten(self::parseDomain($domain, $locale));
            }

            foreach ($english as $key => $value) {
                $expected = self::placeholders($value);
                foreach (self::LOCALES as $locale) {
                    $actual = self::placeholders($values[$locale][$key] ?? null);
                    if ($expected !== $actual) {
                        $mismatches[] = sprintf(
                            '%s.%s [%s] expected {%s} got {%s}',
                            $domain,
                            $key,
                            $locale,
                            implode(', ', $expected),
                            implode(', ', $actual),
                        );
                    }
                }
            }
        }

        self::assertSame(
            [],
            $mismatches,
            "Placeholder mismatch across locales:\n" . implode("\n", $mismatches),
        );
    }

    #[Test]
    public function serviceHelpTranslationsUseOnlyTheCanonicalStructuralPalette(): void
    {
        $allowedColors = ['03', '04', '06', '07', '10', '14'];
        $violations = [];

        foreach (self::SERVICE_DIRS as $domain) {
            foreach (self::LOCALES as $locale) {
                $catalog = self::flatten(self::parseDomain($domain, $locale));
                foreach ($catalog as $key => $value) {
                    if (
                        !is_string($value)
                        || (!str_starts_with($key, 'help.')
                            && !str_ends_with($key, '.help')
                            && !str_ends_with($key, '.short')
                            && !str_ends_with($key, '.syntax'))
                    ) {
                        continue;
                    }

                    /* @var array<int, array<int, string>> $colorMatches */
                    preg_match_all('/\x03(\d{1,2})(?:,(\d{1,2}))?(?!\d)/', $value, $colorMatches, PREG_SET_ORDER);
                    foreach ($colorMatches as $colorMatch) {
                        foreach ([$colorMatch[1], $colorMatch[2] ?? ''] as $color) {
                            if ('' !== $color && !in_array($color, $allowedColors, true)) {
                                $violations[] = sprintf('%s.%s [%s] uses structural color %s', $domain, $key, $locale, $color);
                            }
                        }
                    }
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "HELP translation markup contains colors outside the approved structural palette:\n" . implode("\n", $violations),
        );
    }

    #[Test]
    public function serviceHelpCommandsKeepStyleMarkupAndMarkersInTranslations(): void
    {
        foreach (array_keys(self::SERVICE_DIRS) as $service) {
            $path = self::ROOT . '/src/' . $service . '/Adapter/In/Irc/Command/HelpCommand.php';
            $source = file_get_contents($path);
            self::assertIsString($source, $path);

            self::assertDoesNotMatchRegularExpression('/IrcHelpStyle|\\\\x03|\\\\x0F|[\x03\x0F]/u', $source, $path);
            self::assertDoesNotMatchRegularExpression('/[›ℹ⚠✗●◆]/u', $source, $path);
        }
    }

    /**
     * @param array<string, int>  $domainKeys
     * @param array<string, true> $union
     * @param list<string>        $missing
     */
    private static function collectMissing(
        string $key,
        array $domainKeys,
        array $union,
        bool $allowUnionFallback,
        string $file,
        array &$missing,
    ): void {
        if (!self::looksLikeTranslationKey($key) || self::isDynamicKey($key)) {
            return;
        }
        if (isset($domainKeys[$key])) {
            return;
        }
        if ($allowUnionFallback && isset($union[$key])) {
            return;
        }
        $missing[] = sprintf('%s @ %s', $key, $file);
    }

    private static function looksLikeTranslationKey(string $key): bool
    {
        return 1 === preg_match('/^[a-z][a-z0-9_]*(\\.[a-z0-9_]+)+$/', $key);
    }

    private static function isDynamicKey(string $key): bool
    {
        foreach (self::DYNAMIC_KEY_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    private static function serviceFor(string $relativePath): ?string
    {
        foreach (self::SERVICE_DIRS as $directory => $domain) {
            if (str_starts_with($relativePath, 'src/' . $directory . '/')) {
                return $domain;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function parseDomain(string $domain, string $locale): array
    {
        $parsed = Yaml::parseFile(sprintf('%s/translations/%s.%s.yaml', self::ROOT, $domain, $locale));
        if (!is_array($parsed)) {
            throw new RuntimeException(sprintf('Domain "%s" for locale "%s" is not a map.', $domain, $locale));
        }

        $normalized = [];
        foreach ($parsed as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];
        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;
            if (is_array($value)) {
                $flat += self::flatten($value, $path);

                continue;
            }
            $flat[$path] = $value;
        }

        return $flat;
    }

    /** @return list<string> */
    private static function placeholders(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }
        preg_match_all('/%[a-z_]+%/', $value, $matches);
        $placeholders = $matches[0];
        $placeholders = array_values(array_unique($placeholders));
        sort($placeholders);

        return $placeholders;
    }

    /** @return list<string> */
    private static function phpFiles(string $directory): array
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory "%s" does not exist.', $directory));
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }
            $pathname = $file->getPathname();
            if (!str_ends_with($pathname, '.php')) {
                continue;
            }
            $files[] = $pathname;
        }
        sort($files);

        return $files;
    }
}
