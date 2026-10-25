<?php

declare(strict_types=1);

namespace App\Tests\Shared\Translations;

use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

use function array_flip;
use function array_keys;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_string;
use function mb_strlen;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function sort;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr_count;

use const PREG_SET_ORDER;

/**
 * Guards the translation catalog contract: every locale retains effective service keys,
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

    /** @var array<string, array{string, string, string, string, string, string, string, string, string, string, string}> */
    private const array SYNTAX_TERMS = [
        'ca' => ['ordre', 'opció', 'contrasenya', 'paràmetres', 'domini', 'motiu', 'número', 'patró', 'pàgina', 'canal', 'sobrenom'],
        'de' => ['Befehl', 'Option', 'Passwort', 'Parameter', 'Domain', 'Grund', 'Nummer', 'Muster', 'Seite', 'Kanal', 'Nickname'],
        'el' => ['εντολή', 'επιλογή', 'κωδικός', 'παράμετροι', 'τομέας', 'αιτία', 'αριθμός', 'μοτίβο', 'σελίδα', 'κανάλι', 'ψευδώνυμο'],
        'en' => ['command', 'option', 'password', 'parameters', 'domain', 'reason', 'number', 'pattern', 'page', 'channel', 'nickname'],
        'es' => ['comando', 'opción', 'contraseña', 'parámetros', 'dominio', 'motivo', 'número', 'patrón', 'página', 'canal', 'apodo'],
        'eu' => ['komandoa', 'aukera', 'pasahitza', 'parametroak', 'domeinua', 'arrazoia', 'zenbakia', 'eredua', 'orria', 'kanala', 'ezizena'],
        'fr' => ['commande', 'option', 'motdepasse', 'paramètres', 'domaine', 'raison', 'numéro', 'motif', 'page', 'canal', 'surnom'],
        'gl' => ['comando', 'opción', 'contrasinal', 'parámetros', 'dominio', 'motivo', 'número', 'patrón', 'páxina', 'canle', 'alcume'],
        'it' => ['comando', 'opzione', 'password', 'parametri', 'dominio', 'motivo', 'numero', 'modello', 'pagina', 'canale', 'soprannome'],
        'nl' => ['commando', 'optie', 'wachtwoord', 'parameters', 'domein', 'reden', 'nummer', 'patroon', 'pagina', 'kanaal', 'bijnaam'],
        'pl' => ['komenda', 'opcja', 'hasło', 'parametry', 'domena', 'powód', 'numer', 'wzorzec', 'strona', 'kanał', 'nick'],
        'pt' => ['comando', 'opção', 'palavra-passe', 'parâmetros', 'domínio', 'motivo', 'número', 'padrão', 'página', 'canal', 'apelido'],
        'ro' => ['comandă', 'opțiune', 'parolă', 'parametri', 'domeniu', 'motiv', 'număr', 'model', 'pagină', 'canal', 'poreclă'],
        'tr' => ['komut', 'seçenek', 'şifre', 'parametreler', 'alan_adı', 'sebep', 'numara', 'kalıp', 'sayfa', 'kanal', 'takma_ad'],
    ];

    #[Test]
    public function helpSyntaxSeparatesOptionalCommandAndOptionInEveryLocale(): void
    {
        foreach (self::SYNTAX_TERMS as $locale => [$command, $option]) {
            foreach (['chanserv', 'memoserv', 'operserv'] as $domain) {
                $catalog = self::flatten(self::parseDomain($domain, $locale));
                self::assertSame('HELP [' . $command . '] [' . $option . ']', $catalog['help.syntax'], $domain . '.' . $locale);
            }
        }
    }

    #[Test]
    public function registrationSyntaxLocalizesPasswordWithoutChangingEmailInEveryLocale(): void
    {
        foreach (self::SYNTAX_TERMS as $locale => $terms) {
            $catalog = self::flatten(self::parseDomain('nickserv', $locale));
            self::assertSame('REGISTER <' . $terms[2] . '> [email]', $catalog['register.syntax'], $locale);
        }
    }

    #[Test]
    public function badmailSyntaxLocalizesArgumentsAndPreservesCommandKeywordsInEveryLocale(): void
    {
        foreach (self::SYNTAX_TERMS as $locale => $terms) {
            [, , , $parameters, $domain, $reason, $number, $pattern, $page] = $terms;
            $catalog = self::flatten(self::parseDomain('operserv', $locale));
            self::assertSame('BADMAIL {ADD|DEL|LIST} <' . $parameters . '>', $catalog['badmail.syntax'], $locale);
            self::assertSame('BADMAIL ADD <' . $domain . '> <' . $reason . '>', $catalog['badmail.add.syntax'], $locale);
            self::assertSame('BADMAIL DEL {' . $number . '|ALL}', $catalog['badmail.del.syntax'], $locale);
            self::assertSame('BADMAIL LIST <' . $pattern . '> [' . $page . ']', $catalog['badmail.list.syntax'], $locale);
        }
    }

    #[Test]
    public function founderSyntaxAndTokenGuidanceUseLocalizedChannelAndNicknameInEveryLocale(): void
    {
        foreach (self::SYNTAX_TERMS as $locale => $terms) {
            $catalog = self::flatten(self::parseDomain('chanserv', $locale));
            $syntax = 'SET <#' . $terms[9] . '> FOUNDER <' . $terms[10] . '>';
            self::assertIsString($catalog['set.founder.syntax']);
            self::assertStringStartsWith($syntax . ' [', $catalog['set.founder.syntax'], $locale);
            self::assertIsString($catalog['set.founder.token_required']);
            self::assertStringContainsString($syntax . ' <token>', $catalog['set.founder.token_required'], $locale);
        }
    }

    #[Test]
    public function everyLocalePreservesSpanishCatalogStructureAndPlaceholderOccurrences(): void
    {
        foreach (self::DOMAINS as $domain) {
            $source = self::parseDomain($domain, 'es');

            foreach (self::LOCALES as $locale) {
                self::assertSourceCatalogContract($source, self::parseDomain($domain, $locale), $domain . '.' . $locale);

                $yaml = file_get_contents(sprintf('%s/translations/%s.%s.yaml', self::ROOT, $domain, $locale));
                self::assertIsString($yaml);
                self::assertStringNotContainsString('\\n', $yaml, $domain . '.' . $locale);
            }
        }
    }

    #[Test]
    public function everyLocaleUsesLocalizedCatalogHeaders(): void
    {
        $headers = [
            'ca' => ['Català', 'Traduccions', 'Domini'],
            'de' => ['Deutsch', 'Übersetzungen', 'Domäne'],
            'el' => ['Ελληνικά', 'Μεταφράσεις', 'Τομέας'],
            'en' => ['English', 'translations', 'Domain'],
            'es' => ['Español', 'Traducciones', 'Dominio'],
            'eu' => ['Euskara', 'itzulpenak', 'Domeinua'],
            'fr' => ['Français', 'Traductions', 'Domaine'],
            'gl' => ['Galego', 'Traducións', 'Dominio'],
            'it' => ['Italiano', 'Traduzioni', 'Dominio'],
            'nl' => ['Nederlands', 'vertalingen', 'Domein'],
            'pl' => ['Polski', 'Tłumaczenia', 'Domena'],
            'pt' => ['Português', 'Traduções', 'Domínio'],
            'ro' => ['Română', 'Traduceri', 'Domeniu'],
            'tr' => ['Türkçe', 'çevirileri', 'Alan'],
        ];

        foreach (self::DOMAINS as $domain) {
            foreach ($headers as $locale => [$language, $translations, $domainLabel]) {
                $file = sprintf('%s/translations/%s.%s.yaml', self::ROOT, $domain, $locale);
                $source = file_get_contents($file);
                self::assertIsString($source);
                $lines = explode("\n", $source);

                self::assertStringStartsWith('# ', $lines[0], $file);
                self::assertStringContainsString($translations, $lines[0], $file);
                self::assertStringEndsWith(' — ' . $language, $lines[0], $file);
                self::assertSame('# ' . $domainLabel . ': ' . $domain, $lines[1] ?? '', $file);
            }
        }
    }

    #[Test]
    public function multilineIrcMessagesUseExplicitLiteralBlocksInEveryLocale(): void
    {
        foreach (self::DOMAINS as $domain) {
            foreach (self::LOCALES as $locale) {
                $file = sprintf('%s/translations/%s.%s.yaml', self::ROOT, $domain, $locale);
                $source = file_get_contents($file);
                self::assertIsString($source);
                self::assertDoesNotMatchRegularExpression('/\\\\(?:n|x0a)/i', $source, $file);
                $declarations = preg_replace('/^\h*#.*$/m', '', $source);
                self::assertIsString($declarations);
                self::assertSame(
                    preg_match_all('/!irc\\b/', $declarations),
                    preg_match_all('/:\h+!irc\h+\|[+-]?\h*$/m', $declarations),
                    $file . ' IRC tags must use literal block scalars.',
                );
                $raw = Yaml::parseFile($file, Yaml::PARSE_CUSTOM_TAGS);
                self::assertIsArray($raw);
                array_walk_recursive($raw, static function (mixed $value) use ($file): void {
                    if ($value instanceof TaggedValue) {
                        self::assertSame('irc', $value->getTag(), $file);
                        self::assertIsString($value->getValue());
                        self::assertStringContainsString("\n", $value->getValue(), $file);
                        self::assertMatchesRegularExpression('/\\\\x(?:02|03|0f)/i', $value->getValue(), $file);
                    } elseif (is_string($value) && str_contains($value, "\n")) {
                        self::assertDoesNotMatchRegularExpression('/[\x02\x03\x0f]/', $value, $file);
                    }
                });
            }
        }
    }

    #[Test]
    public function spanishServiceCatalogsUsePhysicalYamlLineBreaks(): void
    {
        foreach (['common', 'nickserv', 'chanserv', 'memoserv', 'operserv'] as $domain) {
            $source = file_get_contents(sprintf('%s/translations/%s.es.yaml', self::ROOT, $domain));

            self::assertIsString($source);
            self::assertFalse(
                str_contains($source, '\n'),
                sprintf('Catalog "%s" must not encode line breaks as escaped \\n sequences.', $domain),
            );
        }
    }

    #[Test]
    public function everyLocalePreservesNickServMultilineHelpSections(): void
    {
        foreach (self::LOCALES as $locale) {
            $catalog = self::flatten(self::parseDomain('nickserv', $locale));
            $operservCatalog = self::flatten(self::parseDomain('operserv', $locale));

            self::assertIsString($catalog['register.pending']);
            self::assertIsString($catalog['identify.help']);
            self::assertIsString($catalog['set.private.help']);
            self::assertIsString($catalog['set.language.help']);
            self::assertIsString($catalog['set.vhost.help']);
            self::assertIsString($catalog['help.help']);
            self::assertIsString($operservCatalog['help.help']);

            self::assertSame(3, substr_count($catalog['register.pending'], "\n"), $locale . ':register.pending must keep its four display lines.');
            self::assertGreaterThanOrEqual(2, substr_count($catalog['identify.help'], "\n"), $locale . ':identify.help must keep its explanatory lines separate.');

            $privateHelp = $catalog['set.private.help'];
            self::assertGreaterThanOrEqual(2, substr_count($privateHelp, "\n"), $locale . ':set.private.help must keep its three guidance lines separate.');

            $help = $catalog['set.vhost.help'];

            self::assertGreaterThanOrEqual(2, substr_count($help, "\n\n"), $locale . ':set.vhost.help must separate its guidance sections.');
            self::assertGreaterThanOrEqual(1, substr_count($catalog['help.help'], "\n"), $locale . ':nickserv.help.help must show both HELP forms separately.');
            self::assertGreaterThanOrEqual(1, substr_count($operservCatalog['help.help'], "\n"), $locale . ':operserv.help.help must show both HELP forms separately.');

            $languageHelp = $catalog['set.language.help'];
            foreach (self::LOCALES as $language) {
                self::assertSame(
                    1,
                    preg_match('/^\s+\x02' . $language . '\x02 \(.+\)$/m', $languageHelp),
                    $locale . ':set.language.help must render each language on its own line.',
                );
            }

            foreach ([
                'role.operclass.help' => ['LIST', 'VIEW', 'SET'],
                'role.modes.help' => ['VIEW', 'SET'],
                'role.vhost.help' => ['VIEW', 'SET'],
            ] as $key => $commands) {
                $operHelp = $operservCatalog[$key];
                self::assertIsString($operHelp);

                foreach ($commands as $command) {
                    self::assertSame(
                        1,
                        preg_match_all('/^[^\n]*\\x0303' . $command . '[^\n]*$/m', $operHelp),
                        $locale . ':operserv.' . $key . ' must keep ' . $command . ' on its own line.',
                    );
                }
            }
        }
    }

    #[Test]
    public function serviceHelpTranslationsDoNotEndWithAnExtraLineBreak(): void
    {
        $trailingLineBreaks = [];
        $literalIrcEscapes = [];

        foreach (array_values(self::SERVICE_DIRS) as $domain) {
            foreach (self::LOCALES as $locale) {
                foreach (self::flatten(self::parseDomain($domain, $locale)) as $key => $value) {
                    if (!is_string($value) || ('help' !== $key && !str_ends_with($key, '.help'))) {
                        continue;
                    }

                    if (str_ends_with($value, "\n")) {
                        $trailingLineBreaks[] = $domain . '.' . $locale . ':' . $key;
                    }

                    if (1 === preg_match('/\\\\x(?:02|03|0[fF])/', $value)) {
                        $literalIrcEscapes[] = $domain . '.' . $locale . ':' . $key;
                    }
                }
            }
        }

        self::assertSame([], $trailingLineBreaks, 'Service HELP translations must not add a blank line after their final text.');
        self::assertSame([], $literalIrcEscapes, 'IRC formatting escapes in HELP values must be decoded by Symfony YAML.');
    }

    #[Test]
    public function globalHelpClearlyDescribesServiceAndTemporaryClientDelivery(): void
    {
        $catalog = self::flatten(self::parseDomain('operserv', 'es'));

        self::assertSame(
            "Permite enviar mensajes a todos los usuarios conectados, utilizando un\n"
            . "servicio existente o un cliente temporal con identidad personalizada.\n\n"
            . "Los mensajes pueden enviarse como PRIVMSG (privado) o NOTICE (aviso).\n\n"
            . "Para utilizar un cliente temporal, debes indicar una máscara con el formato\n"
            . "apodo!ident@vhost, utilizando caracteres válidos para cada campo.\n\n"
            . "El apodo no puede estar registrado ni en uso. El cliente se desconecta\n"
            . 'automáticamente tras el envío y su apodo queda bloqueado durante 24 horas.',
            $catalog['global.help'],
        );
    }

    #[Test]
    public function unknownHelpTopicUsesTheSameErrorFormatInEverySpanishService(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', self::flatten(self::parseDomain('common', 'es')), 'es', 'common');

        foreach ([
            'chanserv' => 'ChanServ',
            'memoserv' => 'MemoServ',
            'nickserv' => 'NickServ',
            'operserv' => 'OperServ',
        ] as $domain => $bot) {
            $parameters = ['%command%' => 'RAQ', '%bot%' => $bot];

            self::assertSame(
                "\x0304✗\x03\x0F Comando desconocido \x0303RAQ\x03\x0F. Usa /msg " . $bot . " \x0303HELP\x03\x0F",
                $translator->trans('unknown_command', $parameters, 'common'),
                $domain,
            );

            self::assertSame(
                "\x0304✗\x03\x0F Opción de ayuda \x0303ASCSAS\x03\x0F desconocida para \x0303SET\x03\x0F. Usa /msg " . $bot . " \x0303HELP SET\x03\x0F",
                $translator->trans('unknown_help_option', ['%command%' => 'SET', '%option%' => 'ASCSAS', '%bot%' => $bot], 'common'),
                $domain,
            );
        }
    }

    #[Test]
    public function rescueTokenInstructionsUseSafeLocalizedFormattingInEveryLocale(): void
    {
        foreach (self::LOCALES as $locale) {
            $translator = new Translator($locale);
            $translator->addLoader('array', new ArrayLoader());
            $translator->addResource('array', self::flatten(self::parseDomain('nickserv', $locale)), $locale, 'nickserv');
            $message = $translator->trans('rescue.issued', ['%token%' => 'example-rescue-token', '%bot%' => 'IdentityKeeper'], 'nickserv');
            $paragraphs = explode("\n\n", $message);

            self::assertCount(4, $paragraphs, $locale);
            self::assertStringEndsWith("\x0303example-rescue-token\x03", $paragraphs[0], $locale);
            self::assertStringEndsWith("/msg IdentityKeeper \x0303VERIFY example-rescue-token\x03", $paragraphs[2], $locale);
            self::assertStringStartsWith("\x0304⚠\x03 ", $paragraphs[3], $locale);
            self::assertSame(6, substr_count($message, "\x03"), $locale);
            self::assertStringNotContainsString("\x02", $message, $locale);
            self::assertDoesNotMatchRegularExpression('/%[a-z_]+%/', $message, $locale);
            foreach ($paragraphs as $paragraph) {
                self::assertStringNotContainsString("\n", $paragraph, $locale);
            }
        }

        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));
        self::assertSame(
            "Token de rescate: \x0303%token%\x03\n\n"
            . "Guarda este token en un lugar seguro. Lo necesitarás para recuperar tu apodo si olvidas tu contraseña.\n\n"
            . "Para confirmarlo, utiliza: /msg %bot% \x0303VERIFY %token%\x03\n\n"
            . "\x0304⚠\x03 IMPORTANTE: Solo el último token generado es válido. Una vez confirmado, no volverá a mostrarse. No lo compartas con nadie.",
            $catalog['rescue.issued'],
        );
    }

    #[Test]
    public function registrationModeHelpExplainsEachOptionOnItsOwnLineInEveryLocale(): void
    {
        foreach (self::LOCALES as $locale) {
            $catalog = self::flatten(self::parseDomain('nickserv', $locale));
            $help = $catalog['regmode.help'];
            self::assertIsString($help);
            $lines = explode("\n", $help);

            if ('es' === $locale) {
                self::assertSame([
                    'Configura el modo de registro para las nuevas cuentas.',
                    '',
                    '• ' . "\x02EMAIL:\x02" . ' Envía el token de verificación al correo electrónico del usuario.',
                    '• ' . "\x02TOKEN:\x02" . ' Entrega el token de verificación directamente por IRC.',
                    '• ' . "\x02STATUS:\x02" . ' Muestra el modo de registro actual sin modificarlo.',
                    '',
                    'Los registros pendientes mantienen el modo con el que se iniciaron.',
                    '',
                    'En modo TOKEN, el comando RESEND permanece',
                    'deshabilitado para todos los usuarios.',
                ], $lines, $locale);

                foreach ($lines as $line) {
                    self::assertLessThanOrEqual(80, mb_strlen(str_replace("\x02", '', $line), 'UTF-8'), $locale);
                }
            } else {
                $paragraphs = explode("\n\n", $help);
                self::assertCount(4, $paragraphs, $locale);
                foreach (['EMAIL', 'TOKEN', 'STATUS'] as $option) {
                    self::assertMatchesRegularExpression('/^• \x02' . $option . ':\x02 .+/m', $paragraphs[1], $locale);
                }
                self::assertNotEmpty($paragraphs[2], $locale . ' pending-registration guidance');
                self::assertStringContainsString('TOKEN', $paragraphs[3], $locale);
                self::assertStringContainsString('RESEND', $paragraphs[3], $locale);
            }
            foreach ($lines as $line) {
                self::assertLessThanOrEqual(80, mb_strlen(str_replace("\x02", '', $line), 'UTF-8'), $locale);
            }
            self::assertStringNotContainsString("\x03", $help, $locale);
        }
    }

    #[Test]
    public function registrationModeHelpAndSyntaxErrorsUseRequiredChoiceBracesInEveryLocale(): void
    {
        foreach (self::LOCALES as $locale) {
            $catalog = self::flatten(self::parseDomain('nickserv', $locale));
            $translator = new Translator($locale);
            $translator->addLoader('array', new ArrayLoader());
            $translator->addResource('array', $catalog, $locale, 'nickserv');
            $translator->addResource('array', self::flatten(self::parseDomain('common', $locale)), $locale, 'common');
            $syntax = $translator->trans('regmode.syntax', [], 'nickserv');

            self::assertSame('REGMODE {EMAIL|TOKEN|STATUS}', $syntax, $locale);
            $error = $translator->trans('error.syntax', ['%syntax%' => $syntax], 'common');
            if ('error.syntax' === $error) {
                $error = $translator->trans('error.syntax', ['%syntax%' => $syntax], 'nickserv');
            }
            self::assertStringContainsString("\x02REGMODE {EMAIL|TOKEN|STATUS}\x02", $error, $locale);
            self::assertStringNotContainsString('REGMODE EMAIL|TOKEN|STATUS', $error, $locale);
        }
    }

    #[Test]
    public function registrationModeChangesUseServiceSuccessStylingInEveryLocale(): void
    {
        foreach (self::LOCALES as $locale) {
            $translator = new Translator($locale);
            $translator->addLoader('array', new ArrayLoader());
            $translator->addResource('array', self::flatten(self::parseDomain('nickserv', $locale)), $locale, 'nickserv');

            foreach (['EMAIL', 'TOKEN'] as $mode) {
                $message = $translator->trans('regmode.changed', ['%mode%' => $mode], 'nickserv');
                self::assertStringStartsWith("\x0303✔\x03 ", $message, $locale);
                self::assertStringContainsString("\x02" . $mode . "\x02", $message, $locale);
                self::assertStringNotContainsString('%mode%', $message, $locale);
            }
        }
    }

    #[Test]
    public function registrationModeAndRescueRepliesUseConsistentOutcomeStylesInEveryLocale(): void
    {
        $styles = [
            'regmode.status' => "\x0307ℹ\x03 ",
            'rescue.confirmed' => "\x0303✔\x03 ",
            'rescue.unavailable' => "\x0304✗\x03 ",
            'rescue.locked_out' => "\x0304✗\x03 ",
            'rescue.throttled' => "\x0304✗\x03 ",
            'rescue.replaced' => "\x0303✔\x03 ",
            'rescue.replacement_unavailable' => "\x0307ℹ\x03 ",
            'rescue.temporarily_unavailable' => "\x0304✗\x03 ",
        ];

        foreach (self::LOCALES as $locale) {
            $catalog = self::flatten(self::parseDomain('nickserv', $locale));
            foreach ($styles as $key => $prefix) {
                $message = $catalog[$key];
                self::assertIsString($message);
                self::assertStringStartsWith($prefix, $message, $locale . ':' . $key);
            }
            foreach (['regmode.status' => 'mode', 'rescue.locked_out' => 'seconds', 'rescue.throttled' => 'minutes'] as $key => $parameter) {
                $message = $catalog[$key];
                self::assertIsString($message);
                self::assertStringContainsString("\x02%" . $parameter . "%\x02", $message, $locale . ':' . $key);
            }
            self::assertIsString($catalog['rescue.unavailable']);
            self::assertStringContainsString("\x0303VERIFY\x03", $catalog['rescue.unavailable'], $locale);
        }
    }

    #[Test]
    public function optionalRescueTokensAndConfirmationCommandsAreCopyableInEveryLocale(): void
    {
        foreach (self::LOCALES as $locale) {
            $catalog = self::flatten(self::parseDomain('nickserv', $locale));
            $message = $catalog['rescue.optional_replacement'];
            self::assertIsString($message);
            $paragraphs = explode("\n\n", $message);

            self::assertCount(4, $paragraphs, $locale);
            self::assertStringStartsWith("\x0307→\x03 ", $paragraphs[0], $locale);
            self::assertStringEndsWith("\x0303%token%\x03", $paragraphs[0], $locale);
            self::assertStringEndsWith("/msg %bot% \x0303VERIFY %token%\x03", $paragraphs[1], $locale);
            self::assertNotEmpty($paragraphs[2], $locale);
            self::assertNotEmpty($paragraphs[3], $locale);
        }
    }

    #[Test]
    public function registrationAndRecoveryGuidanceUsesServiceStylesInEveryLocale(): void
    {
        $styles = [
            'register.token_unavailable' => "\x0304✗\x03 ",
            'register.already_pending' => "\x0307→\x03 ",
            'verify.invalid_token' => "\x0304✗\x03 ",
            'resend.disabled' => "\x0307ℹ\x03 ",
            'recover.pending_deletion' => "\x0304✗\x03 ",
            'recover.instructions' => "\x0307ℹ\x03 ",
            'recover.pending' => "\x0307→\x03 ",
            'recover.throttled' => "\x0304✗\x03 ",
            'recover.invalid_token' => "\x0304✗\x03 ",
            'set.email.token_required' => "\x0304✗\x03 ",
        ];

        foreach (self::LOCALES as $locale) {
            $catalog = self::flatten(self::parseDomain('nickserv', $locale));
            foreach ($styles as $key => $prefix) {
                $message = $catalog[$key];
                self::assertIsString($message);
                self::assertStringStartsWith($prefix, $message, $locale . ':' . $key);
            }
            foreach (['register.already_pending', 'recover.pending', 'recover.pending_deletion'] as $key) {
                $message = $catalog[$key];
                self::assertIsString($message);
                self::assertStringContainsString("\x02%nickname%\x02", $message, $locale . ':' . $key);
            }
            foreach (['register.already_pending', 'recover.pending'] as $key) {
                $message = $catalog[$key];
                self::assertIsString($message);
                self::assertStringContainsString("\x0303VERIFY <token>\x03", $message, $locale . ':' . $key);
                self::assertStringContainsString("\x0303RESEND\x03", $message, $locale . ':' . $key);
            }
            self::assertIsString($catalog['recover.throttled']);
            self::assertIsString($catalog['set.email.token_required']);
            self::assertStringContainsString("\x02%minutes%\x02", $catalog['recover.throttled'], $locale);
            self::assertStringContainsString("\x0303SET EMAIL <email> <token>\x03", $catalog['set.email.token_required'], $locale);
        }
    }

    #[Test]
    public function registrationEmailsDocumentVerificationAndFutureRecoveryInEveryLocale(): void
    {
        foreach (self::LOCALES as $locale) {
            $catalog = TranslationCatalog::parseFile(__DIR__ . '/../../../translations/mail.' . $locale . '.yaml');
            foreach (['register_verification_body', 'resend_verification_body'] as $key) {
                $body = $catalog[$key] ?? null;
                self::assertIsString($body);
                self::assertStringContainsString('/msg %bot% VERIFY %token%', $body, $locale . ':' . $key);
                self::assertStringContainsString('/msg %bot% RECOVER %nickname% %token%', $body, $locale . ':' . $key);
            }
        }
    }

    #[Test]
    public function serviceCatalogValuesDoNotHardcodeConfigurableNicknames(): void
    {
        foreach (self::SERVICE_DIRS as $domain) {
            foreach (self::LOCALES as $locale) {
                foreach (self::flatten(self::parseDomain($domain, $locale)) as $key => $value) {
                    self::assertIsString($value);
                    self::assertFalse(
                        self::containsHardcodedServiceNickname($value),
                        sprintf('Hardcoded service nickname in %s.%s [%s].', $domain, $key, $locale),
                    );
                }
            }
        }
    }

    #[Test]
    public function nicknameGuardDistinguishesPlaceholdersAndPermissionTokensFromProse(): void
    {
        foreach (['%bot%', '%nickserv%', '%chanserv%', '%memoserv%', '%operserv%', 'chanserv.drop.force', 'nickserv.forbidvhost', 'operserv.kill'] as $token) {
            self::assertFalse(self::containsHardcodedServiceNickname($token), $token);
        }

        foreach (['ChanServ', 'NICKSERV', 'memoserv', 'OperServ', 'ChanServ.', 'chanserv.drop.force then ChanServ', '%nickserv% then NickServ'] as $value) {
            self::assertTrue(self::containsHardcodedServiceNickname($value), $value);
        }
    }

    #[Test]
    public function serviceTranslationsRenderConfiguredNicknamesInEveryLocale(): void
    {
        $nicknames = ['chanserv' => 'ChannelKeeper', 'nickserv' => 'IdentityKeeper', 'memoserv' => 'MemoKeeper', 'operserv' => 'NetworkKeeper'];
        $providers = [];
        foreach ($nicknames as $domain => $nickname) {
            $provider = $this->createStub(ServiceNicknameProviderInterface::class);
            $provider->method('getServiceKey')->willReturn($domain);
            $provider->method('getNickname')->willReturn($nickname);
            $providers[] = $provider;
        }
        $registry = new ServiceNicknameRegistry($providers);
        $keys = [
            'chanserv' => ['help.general_footer', 'ircoponly.help', 'info.pending_deletion_notice', 'error.not_identified', 'register.help'],
            'nickserv' => ['help.general_footer', 'forbidvhost.help', 'set.password.help'],
            'memoserv' => ['help.general_footer', 'error.not_identified'],
            'operserv' => ['help.general_footer', 'error.not_identified'],
        ];

        foreach (self::LOCALES as $locale) {
            $translator = new Translator($locale);
            $translator->addLoader('array', new ArrayLoader());
            $translator->addResource('array', self::flatten(self::parseDomain('common', $locale)), $locale, 'common');
            foreach ($keys as $domain => $domainKeys) {
                $catalog = self::flatten(self::parseDomain($domain, $locale));
                $translator->addResource('array', $catalog, $locale, $domain);
                $params = $registry->getAllPlaceholders($nicknames[$domain]);
                $params['%marker%'] = 'ℹ';
                $params['%syntax%'] = 'HELP <command>';

                foreach ($domainKeys as $key) {
                    $message = $translator->trans($key, $params, 'common', $locale);
                    if ($key === $message) {
                        $message = $translator->trans($key, $params, $domain, $locale);
                    }
                    $label = sprintf('%s.%s [%s]', $domain, $key, $locale);
                    self::assertNotSame($key, $message, $label);
                    self::assertDoesNotMatchRegularExpression('/%[a-z_]+%/', $message, $label);
                    self::assertFalse(self::containsHardcodedServiceNickname($message), $label);
                    if (in_array($key, ['help.general_footer', 'ircoponly.help'], true)) {
                        self::assertStringContainsString($nicknames[$domain], $message, $label);
                    }
                    if (in_array($key, ['error.not_identified', 'register.help'], true)) {
                        self::assertStringContainsString($nicknames['nickserv'], $message, $label);
                    }
                    if (in_array($key, ['info.pending_deletion_notice', 'set.password.help'], true)) {
                        self::assertStringNotContainsString($nicknames[$domain], $message, $label);
                    }
                }
            }
        }
    }

    private static function containsHardcodedServiceNickname(string $value): bool
    {
        // Remove only individual dynamic tokens and canonical permission identifiers,
        // never the surrounding prose: a permission must not conceal a hardcoded bot.
        $prose = preg_replace('/%[a-z_]+%|\b(?:chanserv|nickserv|memoserv|operserv)\.[a-z_]+(?:\.[a-z_]+)*\b/', '', $value);

        return 1 === preg_match('/\b(?:ChanServ|NickServ|MemoServ|OperServ)\b/i', $prose ?? $value);
    }

    /** @var list<string> */
    private const array DYNAMIC_KEY_PATTERNS = [
        '/^set\\.(password|email|language|timezone|private|msg|vhost)\\.(short|help|syntax|example)$/',
        '/^saset\\.(password|email|language|timezone|private|msg|vhost)\\.(short|help|syntax|example)$/',
        '/^history\\.extra\\.[a-z_]+$/',
        '/^(op|deop|voice|devoice|halfop|dehalfop|admin|deadmin)\\.(done|notice_grant)$/',
        '/^(admin|halfop|op|voice)\\.(not_supported|user_not_on_channel)$/',
        '/^ignore\\.[a-z_]+\\.(syntax|short|help)$/',
    ];

    #[Test]
    public function serviceHelpIntroductionsUseLocalizedDescriptionsInEveryLocale(): void
    {
        $introductions = [
            'ca' => [
                'nickserv' => "Permet registrar i protegir el teu sobrenom perquè\naltres usuaris no el puguin fer servir. Gestiona la\nseguretat del compte, personalitza les preferències\ni recupera l'accés quan ho necessitis.",
                'chanserv' => "Permet registrar i administrar canals, configurar-ne les opcions i controlar\nqui hi pot accedir. Gestiona els nivells d'accés i els privilegis dels usuaris\nperquè els responsables mantinguin el control del canal.",
                'memoserv' => "Permet enviar i rebre missatges entre usuaris registrats i enviar avisos als\ncanals. Els missatges es desen per consultar-los més tard, encara que el\ndestinatari no estigui connectat.",
                'operserv' => "Permet gestionar els operadors de la xarxa i els\nseus permisos, aplicar restriccions d'accés i\ndesconnectar usuaris. També permet enviar comunicats\ngenerals i gestionar els missatges de benvinguda.",
            ],
            'de' => [
                'nickserv' => "Registriert und schützt deinen Nickname, damit andere Benutzer ihn nicht\nverwenden können. Verwalte die Kontosicherheit, passe deine Einstellungen an\nund stelle bei Bedarf den Zugriff wieder her.",
                'chanserv' => "Mit diesem Dienst kannst du Channels registrieren und verwalten, ihre\nEinstellungen festlegen und den Zugriff kontrollieren. Verwalte Zugriffsstufen\nund Benutzerrechte, damit der Channel unter der Kontrolle seiner\nVerantwortlichen bleibt.",
                'memoserv' => "Ermöglicht den Versand und Empfang von Nachrichten zwischen registrierten\nBenutzern sowie Mitteilungen an Channels. Nachrichten werden zur späteren\nAbfrage gespeichert, auch wenn der Empfänger nicht verbunden ist.",
                'operserv' => "Verwaltet Netzwerk-Operatoren und Berechtigungen, setzt Zugriffsbeschränkungen\ndurch und trennt Benutzer vom Netzwerk. Außerdem kannst du allgemeine\nMitteilungen\nsenden und Netzwerk-Willkommensnachrichten verwalten.",
            ],
            'el' => [
                'nickserv' => "Σας επιτρέπει να καταχωρείτε και να προστατεύετε το ψευδώνυμό σας, ώστε να μην\nτο χρησιμοποιούν άλλοι χρήστες. Διαχειριστείτε την ασφάλεια του λογαριασμού σας,\nπροσαρμόστε τις προτιμήσεις σας και ανακτήστε την πρόσβαση όποτε χρειαστεί.",
                'chanserv' => "Σας επιτρέπει να καταχωρείτε και να διαχειρίζεστε κανάλια, να ρυθμίζετε τις\nεπιλογές τους και να ελέγχετε ποιος έχει πρόσβαση σε αυτά. Διαχειρίζεται τα\nεπίπεδα πρόσβασης και τα προνόμια των χρηστών, ώστε το κανάλι να παραμένει υπό\nτον έλεγχο των υπευθύνων του.",
                'memoserv' => "Επιτρέπει την αποστολή και λήψη μηνυμάτων μεταξύ καταχωρημένων χρηστών και την\nαποστολή ειδοποιήσεων σε κανάλια. Τα μηνύματα αποθηκεύονται για να τα διαβάσετε\nαργότερα, ακόμη και αν ο παραλήπτης δεν είναι συνδεδεμένος.",
                'operserv' => "Σας επιτρέπει να διαχειρίζεστε τους χειριστές του δικτύου και τα δικαιώματά\nτους, να εφαρμόζετε περιορισμούς πρόσβασης και να αποσυνδέετε χρήστες. Επιτρέπει\nεπίσης την αποστολή γενικών ανακοινώσεων και τη διαχείριση των μηνυμάτων\nκαλωσορίσματος του δικτύου.",
            ],
            'en' => [
                'nickserv' => "Lets you register and protect your nickname to prevent others from using it.\nManage your account security, customize your preferences and recover access\nwhen you need it.",
                'chanserv' => "Lets you register and manage channels, configure their options and control\nwho can access them. Manages access levels and user privileges to keep each\nchannel under the control of its owners.",
                'memoserv' => "Lets registered users exchange messages and send notices to channels.\nMessages are saved for later reading, even when the recipient is offline.",
                'operserv' => "Lets you manage network operators and their permissions, apply access\nrestrictions and disconnect users. You can also send network-wide\nannouncements and manage network welcome messages.",
            ],
            'es' => [
                'nickserv' => "Permite registrar y proteger tu apodo para evitar que otros usuarios lo\nutilicen. Gestiona la seguridad de tu cuenta, personaliza tus preferencias y\nrecupera el acceso cuando lo necesites.",
                'chanserv' => "Permite registrar y administrar canales, configurar sus opciones y controlar\nquién puede acceder a ellos. Gestiona los niveles de acceso y los privilegios de\nlos usuarios para mantener el canal bajo el control de sus responsables.",
                'memoserv' => "Permite enviar y recibir mensajes entre usuarios registrados y enviar avisos a\ncanales. Los mensajes quedan guardados para consultarlos más tarde, aunque el\ndestinatario no esté conectado.",
                'operserv' => "Permite gestionar los operadores de la red y sus permisos, aplicar restricciones\nde acceso y desconectar usuarios. También permite enviar comunicados generales y\nadministrar los mensajes de bienvenida de la red.",
            ],
            'eu' => [
                'nickserv' => "Zure ezizena erregistratu eta babesteko aukera ematen du, beste erabiltzaileek\nerabil ez dezaten. Kudeatu zure kontuaren segurtasuna, pertsonalizatu zure\nhobespenak eta berreskuratu sarbidea behar duzunean.",
                'chanserv' => "Kanalak erregistratzeko eta kudeatzeko aukera ematen du: ezarpenak\nkonfiguratu, sarbidea kontrolatu eta erabiltzaileen sarbide-mailak eta\npribilegioak kudeatu, arduradunek kanalen kontrola izan dezaten.",
                'memoserv' => "Erregistratutako erabiltzaileen artean mezuak bidali eta jasotzeko eta\nkanaletara oharrak bidaltzeko aukera ematen du. Mezuak geroago kontsultatzeko\ngordetzen dira, hartzailea konektatuta ez badago ere.",
                'operserv' => "Sareko operadoreak eta haien baimenak kudeatzeko, sarbide-murrizketak\naplikatzeko eta erabiltzaileak deskonektatzeko aukera ematen du. Sareko\nkomunikatu orokorrak bidali eta ongietorri-mezuak ere kudea ditzake.",
            ],
            'fr' => [
                'nickserv' => "Permet d’enregistrer et de protéger votre pseudonyme afin d’empêcher son\nutilisation par d’autres. Gérez la sécurité de votre compte, personnalisez vos\npréférences et récupérez l’accès lorsque nécessaire.",
                'chanserv' => "Permet d’enregistrer et de gérer des salons, de configurer leurs options et de\ncontrôler qui peut y accéder. Gérez les niveaux d’accès et les privilèges des\nutilisateurs pour que le salon reste sous le contrôle de ses responsables.",
                'memoserv' => "Permet d’envoyer et de recevoir des messages entre utilisateurs enregistrés et\nd’envoyer des annonces aux salons. Les messages sont conservés pour être\nconsultés\nplus tard, même lorsque le destinataire est déconnecté.",
                'operserv' => "Permet de gérer les opérateurs du réseau et leurs permissions, d’appliquer des\nrestrictions d’accès et de déconnecter des utilisateurs. Permet aussi d’envoyer\ndes annonces générales et de gérer les messages de bienvenue du réseau.",
            ],
            'gl' => [
                'nickserv' => "Permite rexistrar e protexer o teu alcume para impedir que outras persoas o\nutilicen. Xestiona a seguridade da túa conta, personaliza as preferencias e\nrecupera o acceso cando o necesites.",
                'chanserv' => "Permite rexistrar e xestionar canles, configurar as súas opcións e controlar\nquen pode acceder a elas. Xestiona os niveis de acceso e os privilexios\ndaspersoas usuarias para manter a canle baixo o control das persoas\nresponsables.",
                'memoserv' => "Permite enviar e recibir mensaxes entre persoas usuarias rexistradas e enviar\navisos ás canles. As mensaxes gárdanse para consultalas máis tarde, aínda que a\npersoa destinataria non estea conectada.",
                'operserv' => "Permite xestionar as persoas operadoras da rede e os seus permisos, aplicar\nrestricións de acceso e desconectar persoas usuarias. Tamén permite enviar\ncomunicados xerais e administrar as mensaxes de benvida da rede.",
            ],
            'it' => [
                'nickserv' => "Permette di registrare e proteggere il tuo nickname, impedendo ad altri utenti\ndi usarlo. Gestisce la sicurezza dell'account, personalizza le preferenze e\nconsente di recuperare l'accesso quando serve.",
                'chanserv' => "Permette di registrare e amministrare i canali, configurarne le opzioni e\ncontrollare chi può accedervi. Gestisce i livelli di accesso e i privilegi degli\nutenti, mantenendo il canale sotto il controllo dei responsabili.",
                'memoserv' => "Permette di inviare e ricevere messaggi tra utenti registrati e di inviare\navvisi\nai canali. I messaggi vengono conservati per consultarli in seguito, anche se il\ndestinatario non è connesso.",
                'operserv' => "Permette di gestire gli operatori di rete e i relativi permessi, applicare\nrestrizioni di accesso e disconnettere gli utenti. Consente inoltre di inviare\ncomunicati generali e gestire i messaggi di benvenuto della rete.",
            ],
            'nl' => [
                'nickserv' => "Registreert en beschermt je bijnaam zodat anderen deze niet kunnen gebruiken.\nBeheer de accountbeveiliging, pas voorkeuren aan en herstel toegang wanneer\nnodig.",
                'chanserv' => "Met deze dienst kun je kanalen registreren en beheren, instellingen aanpassen\nen bepalen wie toegang heeft. Beheer toegangslevels en gebruikersrechten om het\nkanaal onder controle van de verantwoordelijken te houden.",
                'memoserv' => "Hiermee kunnen geregistreerde gebruikers elkaar berichten sturen en meldingen\naan kanalen sturen. Berichten worden bewaard om later te lezen, ook als de\nontvanger niet verbonden is.",
                'operserv' => "Beheert netwerkoperators en hun rechten, past toegangsbeperkingen toe en\nverbreekt gebruikersverbindingen. Ook kun je algemene aankondigingen sturen en\nwelkomstberichten voor het netwerk beheren.",
            ],
            'pl' => [
                'nickserv' => "Pozwala zarejestrować i chronić pseudonim przed użyciem przez inne osoby.\nZarządzaj bezpieczeństwem konta, dostosuj preferencje i odzyskaj dostęp w razie\npotrzeby.",
                'chanserv' => "Umożliwia rejestrowanie i zarządzanie kanałami, konfigurowanie ich opcji oraz\nkontrolowanie dostępu. Zarządza poziomami dostępu i uprawnieniami użytkowników,\naby kanał pozostawał pod kontrolą osób odpowiedzialnych.",
                'memoserv' => "Umożliwia wysyłanie i odbieranie wiadomości między zarejestrowanymi\nużytkownikami oraz wysyłanie ogłoszeń na kanały. Wiadomości są przechowywane do\npóźniejszego odczytu, nawet gdy odbiorca jest offline.",
                'operserv' => "Umożliwia zarządzanie operatorami sieci i ich uprawnieniami, stosowanie\nograniczeń dostępu oraz rozłączanie użytkowników. Pozwala też wysyłać ogłoszenia\nogólne i zarządzać wiadomościami powitalnymi sieci.",
            ],
            'pt' => [
                'nickserv' => "Permite registar e proteger o seu nickname para impedir que outros utilizadores\no utilizem. Gere a segurança da conta, personalize as preferências e recupere\no acesso quando precisar.",
                'chanserv' => "Permite registar e administrar canais, configurar as respetivas opções e\ncontrolar quem lhes pode aceder. Gere os níveis de acesso e os privilégios dos\nutilizadores para manter o canal sob o controlo dos seus responsáveis.",
                'memoserv' => "Permite enviar e receber mensagens entre utilizadores registados e enviar\navisos para canais. As mensagens ficam guardadas para consulta posterior,\nmesmo que o destinatário não esteja ligado.",
                'operserv' => "Permite gerir os operadores da rede e as respetivas permissões, aplicar\nrestrições de acesso e desligar utilizadores. Também permite enviar\ncomunicados gerais e gerir as mensagens de boas-vindas da rede.",
            ],
            'ro' => [
                'nickserv' => "Înregistrează și protejează porecla ta pentru a împiedica folosirea de către\nalți utilizatori. Gestionează securitatea contului, personalizează preferințele\nși recuperează accesul când ai nevoie.",
                'chanserv' => "Permite înregistrarea și administrarea canalelor, configurarea opțiunilor și\ncontrolul accesului. Gestionează nivelurile de acces și privilegiile\nutilizatorilor\npentru a păstra canalul sub controlul celor responsabili.",
                'memoserv' => "Permite trimiterea și primirea mesajelor între utilizatori înregistrați și\ntrimiterea anunțurilor către canale. Mesajele sunt păstrate pentru consultare\nulterioară, chiar dacă destinatarul nu este conectat.",
                'operserv' => "Gestionează operatorii rețelei și permisiunile lor, aplică restricții de acces\nși deconectează utilizatorii. De asemenea, permite trimiterea de anunțuri\ngenerale\nși administrarea mesajelor de întâmpinare ale rețelei.",
            ],
            'tr' => [
                'nickserv' => "Takma adınızı kaydedip başkalarının kullanmasını önlemek için korur. Hesap\ngüvenliğini yönetin, tercihlerinizi özelleştirin ve gerektiğinde erişimi\nkurtarın.",
                'chanserv' => "Kanalları kaydetmenizi ve yönetmenizi, seçeneklerini yapılandırmanızı ve\nerişimi denetlemenizi sağlar. Kanalın sorumluların kontrolünde kalması için\nerişim düzeylerini ve kullanıcı ayrıcalıklarını yönetir.",
                'memoserv' => "Kayıtlı kullanıcılar arasında mesaj gönderip almayı ve kanallara duyuru\ngöndermeyi sağlar. Alıcı çevrimdışı olsa bile mesajlar daha sonra okunmak üzere\nsaklanır.",
                'operserv' => "Ağ operatörlerini ve izinlerini yönetmeyi, erişim kısıtlamaları uygulamayı ve\nkullanıcıların bağlantısını kesmeyi sağlar. Ayrıca genel duyurular göndermeye ve\nağın karşılama mesajlarını yönetmeye yarar.",
            ],
        ];

        foreach (self::LOCALES as $locale) {
            foreach ($introductions[$locale] as $domain => $expected) {
                $catalog = self::flatten(self::parseDomain($domain, $locale));

                self::assertSame($expected, $catalog['help.intro'] ?? null, $domain . '.' . $locale . ': help.intro');
                $lines = explode("\n", $expected);
                self::assertGreaterThan(1, count($lines), $domain . '.' . $locale . ' help.intro should span multiple lines');
                foreach ($lines as $line) {
                    self::assertLessThanOrEqual(80, mb_strlen($line), $domain . '.' . $locale . ' help.intro line width');
                }
            }
        }
    }

    #[Test]
    public function spanishHelpExamplesUseSingleLineTranslationValues(): void
    {
        foreach (['nickserv', 'chanserv', 'memoserv', 'operserv'] as $domain) {
            $catalog = self::flatten(self::parseDomain($domain, 'es'));

            foreach ($catalog as $key => $value) {
                if (1 !== preg_match('/(?:^|[._])(?:native_)?examples?\.(?:\d+)$|(?:^|[._])(?:native_)?example$/', $key)
                    && !str_ends_with($key, '.example')
                ) {
                    continue;
                }

                self::assertIsString($value, sprintf('%s.%s must be a scalar example value.', $domain, $key));
                self::assertStringNotContainsString("\n", $value, sprintf('%s.%s must be one line.', $domain, $key));
            }
        }
    }

    #[Test]
    public function allLocalesRetainBaselineEffectiveKeys(): void
    {
        foreach (self::DOMAINS as $domain) {
            $isService = in_array($domain, array_values(self::SERVICE_DIRS), true);
            $expectedCatalog = $isService
                ? self::effectiveServiceCatalog($domain, 'en')
                : self::flatten(self::parseDomain($domain, 'en'));
            $expected = array_keys($expectedCatalog);
            sort($expected);

            foreach (self::LOCALES as $locale) {
                $actualCatalog = $isService
                    ? self::effectiveServiceCatalog($domain, $locale)
                    : self::flatten(self::parseDomain($domain, $locale));
                $missing = [];
                foreach ($expected as $key) {
                    if (!isset($actualCatalog[$key])) {
                        $missing[] = $key;
                    }
                }

                self::assertSame([], $missing, sprintf('Missing baseline keys in %s for domain "%s".', $locale, $domain));

                if ('mail' === $domain) {
                    $actual = array_keys($actualCatalog);
                    sort($actual);
                    self::assertSame($expected, $actual, sprintf('Key mismatch between en and %s in domain "%s".', $locale, $domain));
                }
            }
        }
    }

    #[Test]
    public function everyKeyReferencedByCodeIsDefined(): void
    {
        $catalog = [];
        $union = [];
        foreach (self::DOMAINS as $domain) {
            $catalog[$domain] = [];
            foreach (self::LOCALES as $locale) {
                $keys = in_array($domain, array_values(self::SERVICE_DIRS), true)
                    ? self::effectiveServiceCatalog($domain, $locale)
                    : self::flatten(self::parseDomain($domain, $locale));
                $catalog[$domain] += array_flip(array_keys($keys));
            }
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
    public function spanishHelpErrorsKeepTheMessagePrefixUncolored(): void
    {
        $common = self::flatten(self::parseDomain('common', 'es'));
        self::assertSame(
            "\x0304✗\x03\x0F Comando desconocido \x0303%command%\x03\x0F. Usa /msg %bot% \x0303HELP\x03\x0F",
            $common['unknown_command'] ?? null,
        );
        self::assertSame(
            "\x0304✗\x03\x0F Opción de ayuda \x0303%option%\x03\x0F desconocida para \x0303%command%\x03\x0F. Usa /msg %bot% \x0303HELP %command%\x03\x0F",
            $common['unknown_help_option'] ?? null,
        );

        foreach (self::SERVICE_DIRS as $domain) {
            $catalog = self::flatten(self::parseDomain($domain, 'es'));
            self::assertArrayNotHasKey('help.unknown_command', $catalog, $domain . ' must use the common unknown_command key.');
            self::assertArrayNotHasKey('help.unknown_subcommand', $catalog, $domain . ' must use common unknown_help_option.');
            self::assertArrayNotHasKey('help.unknown_option', $catalog, $domain . ' must use common unknown_help_option.');
        }
    }

    #[Test]
    public function spanishCommandReferencesKeepTheBotPrefixUncoloredAndHighlightTheCommand(): void
    {
        foreach (['common', ...array_values(self::SERVICE_DIRS)] as $domain) {
            foreach (self::flatten(self::parseDomain($domain, 'es')) as $key => $value) {
                if (!is_string($value) || !str_contains($value, '/msg')) {
                    continue;
                }

                $label = $domain . '.' . $key;
                self::assertDoesNotMatchRegularExpression('/\x03\d{1,2}\/msg/', $value, $label);
                preg_match_all('/\/msg\s+\S+\s+((?:\x02)?\x03\d{1,2})/', $value, $matches);
                self::assertNotSame([], $matches[1], $label . ' must color the command after the bot nickname.');
                foreach ($matches[1] as $color) {
                    self::assertContains($color, ["\x0303", "\x02\x0303"], $label . ' must use green for the command.');
                }
                self::assertDoesNotMatchRegularExpression(
                    '/\/msg\s+%[^%]+%\s+(?:\x02)?\x0303[A-Z][A-Z0-9]*(?:\s+(?:<[^>]+>|%[a-z_]+%|[A-Z0-9#.*@_-]+))*\x03(?:\x0F)?\.\s*$/',
                    $value,
                    $label . ' must not add punctuation after a terminal command invocation.',
                );
            }
        }
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
            $catalog = self::flatten(self::parseDomain($domain, 'en'));
            foreach (array_keys($catalog) as $key) {
                if (str_contains($source, $key) || self::isDynamicKey($key)) {
                    continue;
                }
                if (self::isIndexedExampleKey($key, $catalog)) {
                    continue;
                }
                if ('common' === $domain && 1 === preg_match('/^duration\.unit\.(year|month|day|hour|minute|second)\.(singular|plural)$/', $key)) {
                    continue;
                }
                if (str_ends_with($key, '.short')) {
                    $permission = $domain . '.' . substr($key, 0, -6);
                    if (str_contains($source, "'" . $permission . "'") || str_contains($source, '"' . $permission . '"')) {
                        continue;
                    }
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
            $isService = in_array($domain, array_values(self::SERVICE_DIRS), true);
            $english = $isService
                ? self::effectiveServiceCatalog($domain, 'en')
                : self::flatten(self::parseDomain($domain, 'en'));
            $values = [];
            foreach (self::LOCALES as $locale) {
                $values[$locale] = $isService
                    ? self::effectiveServiceCatalog($domain, $locale)
                    : self::flatten(self::parseDomain($domain, $locale));
            }

            foreach ($english as $key => $value) {
                $expected = self::placeholders($value);
                foreach (self::LOCALES as $locale) {
                    $actual = self::placeholders($values[$locale][$key] ?? null);
                    if ('nickserv' === $domain && 'es' === $locale) {
                        $spanishPreviewPlaceholders = [
                            'register.help' => ['%duration%'],
                            'register.pending' => ['%bot%', '%duration%', '%email%'],
                            'verify.help' => ['%duration%'],
                            'resend.help' => ['%duration%'],
                            'help.unknown_command' => [],
                            'set.help' => [],
                            'forbidvhost.help' => [],
                            'rename.help' => [],
                        ];
                        if (isset($spanishPreviewPlaceholders[$key])) {
                            self::assertSame(
                                $spanishPreviewPlaceholders[$key],
                                $actual,
                                $locale . ': staged Spanish preview placeholders for ' . $key,
                            );

                            continue;
                        }
                    }
                    if ('nickserv' === $domain && 'error.not_identified' === $key && 'es' === $locale) {
                        // The shared copy targets NickServ explicitly; NickServ's legacy English
                        // catalog used %bot%, while the other services already used %nickserv%.
                        self::assertSame(['%nickserv%'], $actual, $locale . ': common error.not_identified');

                        continue;
                    }
                    if ('chanserv' === $domain && 'levels.list.header' === $key && 'es' === $locale) {
                        self::assertSame(['%channel%'], $actual, $locale . ': staged ChanServ levels header placeholder');

                        continue;
                    }
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
        $allowedColors = ['03', '04', '07', '10', '14'];
        $redColorKeys = [
            'chanserv' => ['help.intro_expiration'],
            'memoserv' => [],
            'nickserv' => ['help.error_marker', 'help.intro_expiration', 'help.warning_marker'],
            'operserv' => [],
        ];
        $violations = [];
        $domains = array_values(self::SERVICE_DIRS);
        $domains[] = 'common';

        foreach ($domains as $domain) {
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
                            if (
                                '04' === $color
                                // Preserve the generic palette guard on these non-HELP command errors.
                                && !in_array($key, ['error.syntax', 'raw.protocol.syntax'], true)
                                && !in_array($key, $redColorKeys[$domain] ?? [], true)
                            ) {
                                $violations[] = sprintf('%s.%s [%s] uses red outside expiration presentation', $domain, $key, $locale);
                            }
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
    public function serviceHelpTemplatesFollowTheCommandReferenceInEveryLocale(): void
    {
        $durationUnits = [
            'ca' => 'dies', 'de' => 'Tage', 'el' => 'ημέρες', 'en' => 'days',
            'es' => 'días', 'eu' => 'egun', 'fr' => 'jours', 'gl' => 'días',
            'it' => 'giorni', 'nl' => 'dagen', 'pl' => 'dni', 'pt' => 'dias',
            'ro' => 'zile', 'tr' => 'günden',
        ];

        foreach (self::SERVICE_DIRS as $domain) {
            foreach (self::LOCALES as $locale) {
                $catalog = self::flatten(self::parseDomain($domain, $locale));
                $message = $domain . ':' . $locale;
                $header = $catalog['help.header'];
                self::assertIsString($header, $message);
                self::assertStringStartsWith("\x02\x0307🤖 %title%\x03\x0F \x0314", $header, $message);
                self::assertStringEndsWith("\x03\x0F", $header, $message);
                self::assertSame("\x0314─────────────────────────────\x03\x0F", $catalog['help.footer'], $message);
                foreach (['general_header', 'options_header', 'ircop_header'] as $section) {
                    $value = $catalog['help.' . $section];
                    self::assertIsString($value, $message);
                    self::assertMatchesRegularExpression('/^\x02 ■ [^\x02\x03\x1F]+\x02$/u', $value, $message);
                }
                foreach (['group_header', 'subgroup_header'] as $group) {
                    self::assertSame("\x0310  ◆ %group%\x03\x0F", $catalog['help.' . $group], $message);
                }
                $marker = $catalog['help.navigation_marker'] ?? "\x0310›\x03\x0F";
                self::assertSame("\x0310›\x03\x0F", $marker, $message);
                foreach (['command_line', 'subcommand_line'] as $row) {
                    $value = $catalog['help.' . $row];
                    self::assertIsString($value, $message);
                    self::assertSame(
                        "    \x0310›\x03\x0F \x02\x0303%command%\x03\x0F%description%",
                        str_replace('%marker%', $marker, $value),
                        $message,
                    );
                }
                $infoMarker = $catalog['help.info_marker'] ?? "\x0310ℹ\x03\x0F";
                self::assertSame("\x0310ℹ\x03\x0F", $infoMarker, $message);
                foreach (['general_footer', 'set_sub_footer'] as $hint) {
                    $value = $catalog['help.' . $hint];
                    self::assertIsString($value, $message);
                    $value = str_replace('%marker%', $infoMarker, $value);
                    self::assertStringStartsWith("\x0310ℹ\x03\x0F ", $value, $message);
                    self::assertMatchesRegularExpression('/^[^\x02\x03\x1F]*\/msg %bot% \x02\x0303(?:HELP [^\x03]+|%syntax%)\x03\x0F[^\x02\x03\x1F]*$/u', substr($value, strlen($infoMarker) + 1), $message);
                }
                $syntax = $catalog['help.syntax_label'];
                self::assertIsString($syntax, $message);
                self::assertMatchesRegularExpression('/^[^\x02\x03\x1F]+ \x02\x0303%syntax%\x03\x0F$/u', $syntax, $message);

                if (!in_array($domain, ['nickserv', 'chanserv'], true)) {
                    continue;
                }
                $expiration = $catalog['help.intro_expiration'];
                self::assertIsString($expiration, $message);
                $duration = "\x1F\x0304%days% " . $durationUnits[$locale] . "\x03\x1F";
                self::assertStringContainsString($duration, $expiration, $message . ': full localized duration is red and underlined');
                self::assertSame(2, substr_count($expiration, "\x1F"), $message);
                $warningMarker = $catalog['help.warning_marker'] ?? "\x0304⚠\x03\x0F";
                self::assertSame("\x0304⚠\x03\x0F", $warningMarker, $message);
                $label = $catalog['help.intro_expiration_label'] ?? '';
                self::assertIsString($label, $message);
                $expiration = str_replace(['%marker%', '%label%'], [$warningMarker, $label], $expiration);
                self::assertStringStartsWith($warningMarker . ' ', $expiration, $message);
                self::assertDoesNotMatchRegularExpression(
                    '/[\x02\x03\x0F\x1F]/',
                    str_replace([$warningMarker, $duration], '', $expiration),
                    $message . ': expiration label and surrounding prose use the default foreground',
                );
            }
        }
    }

    #[Test]
    public function serviceHelpCommandsKeepStyleMarkupAndMarkersInTranslations(): void
    {
        foreach (array_keys(self::SERVICE_DIRS) as $service) {
            $path = self::ROOT . '/src/' . $service . '/Adapter/In/Irc/Command/HelpCommand.php';
            $source = file_get_contents($path);
            self::assertIsString($source, $path);

            self::assertDoesNotMatchRegularExpression('/IrcHelpStyle|\\\\x(?:02|03|0F|1F)|[\x02\x03\x0F\x1F]/u', $source, $path);
            self::assertDoesNotMatchRegularExpression('/[›ℹ⚠✗●🤖■◆]/u', $source, $path);
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

    /** @param array<string, mixed> $catalog */
    private static function isIndexedExampleKey(string $key, array $catalog): bool
    {
        return 1 === preg_match('/^(.+example)s\.[0-9]+$/', $key, $matches)
            && isset($catalog[$matches[1]]);
    }

    #[Test]
    public function indexedExamplesRequireTheirSingularKeyInTheSameCatalog(): void
    {
        self::assertTrue(self::isIndexedExampleKey('help.examples.0', ['help.example' => 'HELP']));
        self::assertTrue(self::isIndexedExampleKey('identify.native_examples.1', ['identify.native_example' => '/NICK']));
        self::assertFalse(self::isIndexedExampleKey('help.examples.0', ['other.example' => 'HELP']));
        self::assertFalse(self::isIndexedExampleKey('help.examples.invalid', ['help.example' => 'HELP']));
        self::assertFalse(self::isIndexedExampleKey('help.example', ['help.example' => 'HELP']));
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

    #[Test]
    public function spanishNickServRegisterExamplesUseUpdatedSamplePassword(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));
        $firstExample = $catalog['register.example'];
        $additionalExample = $catalog['register.examples.0'];

        self::assertIsString($firstExample);
        self::assertIsString($additionalExample);
        $example = implode("\n", [
            $firstExample,
            $additionalExample,
        ]);

        self::assertSame(2, substr_count($example, 'REGISTER Clave$egura_123'));
        self::assertStringNotContainsString('ClaveSegura123', $example);
    }

    #[Test]
    public function spanishCommandExamplesUseGeneratedBotPrefixPlaceholder(): void
    {
        foreach (self::SERVICE_DIRS as $domain) {
            $catalog = self::flatten(self::parseDomain($domain, 'es'));

            foreach ($catalog as $key => $value) {
                if (!is_string($value) || 1 !== preg_match('/(?:^|\\.)examples?(?:\\.\\d+)?$/', $key)) {
                    continue;
                }

                if (!str_contains($value, "\x02\x0303")) {
                    continue;
                }

                self::assertDoesNotMatchRegularExpression('/^[ \t]/', $value, $domain . '.' . $key);
                self::assertSame(1, substr_count($value, '%command_prefix%'), $domain . '.' . $key);
                self::assertStringNotContainsString('/msg ', $value, $domain . '.' . $key);
            }
        }
    }

    #[Test]
    public function spanishNickServSetOptionDescriptionsUseEndUserFriendlyCopy(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));

        self::assertSame('Cambia la forma en que los servicios se comunican contigo.', $catalog['set.msg.short'] ?? null);
        self::assertSame('Establece o quita tu host virtual.', $catalog['set.vhost.short'] ?? null);
        self::assertSame('Muestra o oculta la información de tu apodo.', $catalog['set.private.short'] ?? null);
        self::assertSame("%command_prefix%\x02\x0303SET PASSWORD Clave\$egura_123\x03\x0F", $catalog['set.password.example'] ?? null);
    }

    #[Test]
    public function spanishNickServSetHelpAvoidsDuplicatedTimezoneAndMsgGuidance(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));

        $timezoneHelp = $catalog['set.timezone.help'] ?? null;
        self::assertIsString($timezoneHelp);
        self::assertStringContainsString('HELP SET TIMEZONE', $timezoneHelp);
        self::assertStringNotContainsString('HELP SET TIMEZONE <región>', $timezoneHelp);
        self::assertSame('HELP SET TIMEZONE <región>', $catalog['help.set_timezone.index_syntax'] ?? null);

        $messageHelp = $catalog['set.msg.help'] ?? null;
        self::assertIsString($messageHelp);
        self::assertStringContainsString("\x02PRIVMSG\x02", $messageHelp);
        self::assertStringContainsString("\x02NOTICE\x02", $messageHelp);
        self::assertStringNotContainsString('algunos clientes pueden', $messageHelp);
        self::assertStringNotContainsString('normalmente sin', $messageHelp);
    }

    #[Test]
    public function spanishNickServVhostHelpPresentsRulesInReadableOrder(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));
        $help = $catalog['set.vhost.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Establece un hostname personalizado que otros usuarios verán cuando\n"
            . "estés identificado.\n\n"
            . "Para quitarlo, ejecuta \x02SET VHOST\x02 sin indicar un valor.\n\n"
            . "Solo se permiten letras (a-z, A-Z), dígitos, guiones y puntos.\n"
            . "Cada bloque debe empezar y terminar con una letra o un dígito.\n"
            . 'El hostname puede tener hasta 48 caracteres y debe ser único por apodo.',
            $help,
        );

        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    #[Test]
    public function spanishNickServInfoHelpExplainsPrivacyWithoutCommandReferences(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));
        $help = $catalog['info.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Muestra los datos de registro de un apodo: fecha de alta, estado de\n"
            . "conexión, último mensaje de salida y correo electrónico.\n\n"
            . "El correo solo es visible para el propietario identificado.\n"
            . "Si el apodo es privado, solo su propietario y los Operadores IRC pueden\n"
            . 'ver la información.',
            $help,
        );
        self::assertStringNotContainsString('PRIVATE ON', $help);

        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    #[Test]
    public function spanishChanServInfoHelpDescribesDetailedChannelInformation(): void
    {
        $catalog = self::flatten(self::parseDomain('chanserv', 'es'));
        $help = $catalog['info.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Muestra información detallada de un canal registrado, incluyendo su\n"
            . 'propietario, datos de registro, última actividad y opciones de configuración.',
            $help,
        );

        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    #[Test]
    public function spanishChanServLevelsListHeaderIncludesTheChannelName(): void
    {
        $catalog = self::flatten(self::parseDomain('chanserv', 'es'));

        self::assertSame("\x0303Niveles de %channel%\x03", $catalog['levels.list.header'] ?? null);
    }

    #[Test]
    public function spanishChanServIrcopOnlyHelpAvoidsIrcdModeReferences(): void
    {
        $catalog = self::flatten(self::parseDomain('chanserv', 'es'));
        $help = $catalog['ircoponly.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Solo los operadores IRC autorizados pueden permanecer en el canal. %bot%\n"
            . 'se encarga de aplicar esta restricción.',
            $help,
        );
        self::assertStringNotContainsString('+O', $help);
        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    #[Test]
    public function spanishNoexpireHelpPlacesOnAndOffBehaviorOnTheSecondLine(): void
    {
        $expected = [
            'chanserv' => "Controla si el canal expira por inactividad.\n"
                . 'ON evita la expiración y OFF aplica las reglas normales.',
            'nickserv' => "Controla si el apodo expira por inactividad.\n"
                . 'ON evita la expiración y OFF aplica las reglas normales.',
        ];

        foreach ($expected as $domain => $help) {
            $catalog = self::flatten(self::parseDomain($domain, 'es'));
            $actual = $catalog['noexpire.help'] ?? null;

            self::assertSame($help, $actual, $domain . '.es noexpire.help');
            foreach (explode("\n", $help) as $line) {
                self::assertLessThanOrEqual(80, mb_strlen($line), $domain . '.es noexpire.help line width');
            }
        }
    }

    #[Test]
    public function spanishChanServForbidHelpExplainsRemovalAndReentryPrevention(): void
    {
        $catalog = self::flatten(self::parseDomain('chanserv', 'es'));
        $help = $catalog['forbid.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Prohíbe el uso de un canal, expulsando a todos sus usuarios e impidiendo\n"
            . "que vuelvan a entrar. Si el canal está registrado, se elimina su registro\n"
            . 'antes de aplicar la prohibición.',
            $help,
        );
        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
        self::assertSame('FORBID <#canal> <razón>', $catalog['forbid.syntax'] ?? null);
        self::assertSame(
            "%command_prefix%\x02\x0303FORBID #canal Incumplimiento de normas\x03\x0F",
            $catalog['forbid.example'] ?? null,
        );
        self::assertArrayNotHasKey('forbid.examples.0', $catalog);
    }

    #[Test]
    public function spanishChanServHistoryDeleteOptionDoesNotMentionId(): void
    {
        $catalog = self::flatten(self::parseDomain('chanserv', 'es'));
        $description = $catalog['history.del.short'] ?? null;

        self::assertIsString($description);
        self::assertSame('Eliminar una entrada del historial.', $description);
        self::assertStringNotContainsString('por ID', $description);
    }

    #[Test]
    public function spanishChanServSetHelpUsesThreeRepresentativeExamples(): void
    {
        $catalog = self::flatten(self::parseDomain('chanserv', 'es'));

        self::assertSame([
            "%command_prefix%\x02\x0303SET #canal DESC Un canal comunitario\x03\x0f",
            "%command_prefix%\x02\x0303SET #canal URL https://example.test\x03\x0f",
            "%command_prefix%\x02\x0303SET #canal MLOCK ON\x03\x0f",
        ], [
            $catalog['set.example'] ?? null,
            $catalog['set.examples.0'] ?? null,
            $catalog['set.examples.1'] ?? null,
        ]);
        self::assertArrayNotHasKey('set.examples.2', $catalog);
    }

    #[Test]
    public function spanishChanServSetUrlHelpUsesUrlBeforeItsWebClarification(): void
    {
        $catalog = self::flatten(self::parseDomain('chanserv', 'es'));

        self::assertSame(
            'Cambia la dirección url (web) de un canal. Omite url para borrar.',
            $catalog['set.url.help'] ?? null,
        );
    }

    #[Test]
    public function spanishChanServSetEmailAndEntryMessageUseOmittedValuesToClear(): void
    {
        $catalog = self::flatten(self::parseDomain('chanserv', 'es'));

        self::assertSame('SET <#canal> EMAIL [email]', $catalog['set.email.syntax'] ?? null);
        $emailHelp = $catalog['set.email.help'];
        self::assertIsString($emailHelp);
        $emailHelp = str_replace("\n", ' ', $emailHelp);
        self::assertStringContainsString('Cambia el correo electrónico de un canal. Omite email para borrar.', $emailHelp);
        self::assertStringContainsString('No se admiten dominios de correo bloqueados administrativamente.', $emailHelp);
        self::assertStringContainsString('Excepción administrativa: operadores con acceso de nivel fundador.', $emailHelp);
        self::assertSame(
            "%command_prefix%\x02\x0303SET #canal EMAIL equipo@example.test\x03\x0f",
            $catalog['set.email.example'] ?? null,
        );
        self::assertSame(
            "%command_prefix%\x02\x0303SET #canal EMAIL\x03\x0f",
            $catalog['set.email.examples.0'] ?? null,
        );

        self::assertSame('SET <#canal> ENTRYMSG [mensaje]', $catalog['set.entrymsg.syntax'] ?? null);
        self::assertSame(
            'Establece el mensaje de entrada (NOTICE). Omite el mensaje para borrar.',
            $catalog['set.entrymsg.help'] ?? null,
        );
        self::assertSame(
            "%command_prefix%\x02\x0303SET #canal ENTRYMSG Bienvenido\x03\x0f",
            $catalog['set.entrymsg.example'] ?? null,
        );
        self::assertSame(
            "%command_prefix%\x02\x0303SET #canal ENTRYMSG\x03\x0f",
            $catalog['set.entrymsg.examples.0'] ?? null,
        );

        foreach (['set.email', 'set.entrymsg'] as $setting) {
            foreach (['syntax', 'help', 'example', 'examples.0'] as $key) {
                $value = $catalog[$setting . '.' . $key] ?? null;
                self::assertIsString($value);
                self::assertStringNotContainsString('none', $value);
            }
        }
    }

    #[Test]
    public function spanishChanServAkickAddHelpExplainsDurationAndReasonRequirements(): void
    {
        $catalog = self::flatten(self::parseDomain('chanserv', 'es'));
        $help = $catalog['akick.add.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Añade una máscara a la lista de expulsión automática del canal.\n\n"
            . "Puedes indicar una duración y un motivo. Si incluyes un motivo, debes\n"
            . "especificar también la duración: 0 para permanente, d para días, h para\n"
            . 'horas o m para minutos.',
            $help,
        );
        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }

        self::assertSame('AKICK <#canal> ADD <máscara> [expiración] [razón]', $catalog['akick.add.syntax'] ?? null);
        self::assertSame(
            "%command_prefix%\x02\x0303AKICK #canal ADD *!*@* 0\x03\x0f",
            $catalog['akick.add.example'] ?? null,
        );
        self::assertSame(
            ["%command_prefix%\x02\x0303AKICK #canal ADD *!*@* 7d Spam\x03\x0f"],
            [$catalog['akick.add.examples.0'] ?? null],
        );
        self::assertArrayNotHasKey('akick.add.examples.1', $catalog);
    }

    #[Test]
    public function spanishMemoServIgnoreHelpExplainsPersonalAndChannelLists(): void
    {
        $catalog = self::flatten(self::parseDomain('memoserv', 'es'));
        $help = $catalog['ignore.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Permite gestionar los apodos de los que no deseas recibir mensajes, tanto\n"
            . "en tu cuenta como en un canal registrado. Para modificar la lista de un canal\n"
            . 'necesitas el permiso MEMOCHANGE.',
            $help,
        );
        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }

        self::assertSame('IGNORE {ADD|DEL|LIST} [#canal] [apodo]', $catalog['ignore.syntax'] ?? null);
        self::assertSame('Añade un apodo a la lista de ignorados.', $catalog['ignore.add.short'] ?? null);
        self::assertSame('Elimina un apodo de la lista.', $catalog['ignore.del.short'] ?? null);
        self::assertSame('Muestra los apodos ignorados.', $catalog['ignore.list.short'] ?? null);
        self::assertSame(
            [
                "\x0310›\x03\x0f %command_prefix%\x02\x0303IGNORE ADD Ana\x03\x0f",
                "\x0310›\x03\x0f %command_prefix%\x02\x0303IGNORE DEL #ayuda Ana\x03\x0f",
                "\x0310›\x03\x0f %command_prefix%\x02\x0303IGNORE LIST #ayuda\x03\x0f",
            ],
            [
                $catalog['ignore.example'] ?? null,
                $catalog['ignore.examples.0'] ?? null,
                $catalog['ignore.examples.1'] ?? null,
            ],
        );
    }

    #[Test]
    public function spanishNickServStatusHelpUsesEtceteraInsteadOfFinalOr(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));

        self::assertSame(
            "Muestra el estado actual de un apodo: si está registrado,\n"
            . 'pendiente de verificación, suspendido, etc.',
            $catalog['status.help'] ?? null,
        );
    }

    #[Test]
    public function spanishNickServUserIpHelpOmitsInternalPermissionIdentifier(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));
        $help = $catalog['userip.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Muestra la IP y el hostname reales de un apodo.\n"
            . 'Úsalo con responsabilidad: es información sensible.',
            $help,
        );
        self::assertStringNotContainsString('nickserv.userip', $help);

        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    #[Test]
    public function spanishNickServListHelpKeepsFirstPageNumberWithSentence(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));
        $help = $catalog['list.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Lista los apodos registrados que coinciden con el patrón. El comodín *\n"
            . 'coincide con cualquier secuencia de caracteres; la página opcional empieza en 1.',
            $help,
        );

        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    #[Test]
    public function spanishNickServHistoryHelpOmitsPermissionRequirementLine(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));
        $help = $catalog['history.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Consulta y gestiona el historial de acciones de un apodo. Registra\n"
            . "automáticamente suspensiones, cambios de contraseña o correo y recuperaciones.\n"
            . 'VIEW muestra tres entradas por página; ALL muestra el historial completo.',
            $help,
        );
        self::assertStringNotContainsString('nickserv.history', $help);

        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    #[Test]
    public function spanishNickServSasetHelpOmitsPermissionRequirementLine(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));
        $help = $catalog['saset.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Permite a un IRCop cambiar la configuración de otro apodo registrado. No\n"
            . 'admite apodos de Root, IRCop ni servicios.',
            $help,
        );
        self::assertStringNotContainsString('nickserv.saset', $help);

        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    #[Test]
    public function spanishNickServUnforbidExampleNamesTheProhibitedNickname(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));

        self::assertSame(
            "%command_prefix%\x02\x0303UNFORBID ApodoProhibido\x03\x0F",
            $catalog['unforbid.example'] ?? null,
        );
    }

    #[Test]
    public function spanishNickServForbidHelpDescribesRandomNicknameGenerically(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));
        $help = $catalog['forbid.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Prohíbe permanentemente un apodo para que nadie pueda registrarlo ni usarlo.\n"
            . "Si está registrado se elimina; si está conectado, se le fuerza a cambiar a un\n"
            . 'apodo aleatorio. No se puede prohibir a usuarios Root, IRCops ni servicios.',
            $help,
        );
        self::assertStringNotContainsString('Guest-', $help);

        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    #[Test]
    public function spanishNickServRenameHelpAvoidsInternalPermissionAndPrefixDetails(): void
    {
        $catalog = self::flatten(self::parseDomain('nickserv', 'es'));
        $help = $catalog['rename.help'] ?? null;

        self::assertIsString($help);
        self::assertSame(
            "Fuerza a un apodo conectado a cambiar a un nombre aleatorio con un\n"
            . 'prefijo definido por la red.',
            $help,
        );
        self::assertStringNotContainsString('nickserv.rename', $help);
        self::assertStringNotContainsString('Guest-', $help);
        self::assertStringNotContainsString('%prefix%', $help);

        foreach (explode("\n", $help) as $line) {
            self::assertLessThanOrEqual(80, mb_strlen($line));
        }
    }

    #[Test]
    public function spanishServiceHelpOmitsInternalPermissionRequirements(): void
    {
        foreach (['nickserv', 'chanserv', 'memoserv', 'operserv'] as $domain) {
            $catalog = self::flatten(self::parseDomain($domain, 'es'));

            foreach ($catalog as $key => $value) {
                if (!str_ends_with($key, '.help') || !is_string($value)) {
                    continue;
                }

                self::assertDoesNotMatchRegularExpression(
                    '/\\brequiere(?:n)?\\s+(?:el\\s+)?permiso\\b/iu',
                    $value,
                    sprintf('Internal permission requirement found in %s.%s.', $domain, $key),
                );
                self::assertDoesNotMatchRegularExpression(
                    '/\\b(?:nickserv|chanserv|memoserv|operserv)\\.[a-z][a-z0-9_.]*\\b/iu',
                    $value,
                    sprintf('Internal permission identifier found in %s.%s.', $domain, $key),
                );
            }
        }
    }

    #[Test]
    public function spanishOperServRoleHelpUsesClearOptionsAndGenericSyntax(): void
    {
        $catalog = self::flatten(self::parseDomain('operserv', 'es'));

        self::assertSame(
            "Permite administrar los roles de operadores, sus permisos y su configuración.\n\n"
            . "Los roles ADMIN, OPER y PREOPER están protegidos y no pueden eliminarse.\n"
            . 'Comando exclusivo para usuarios Root.',
            $catalog['role.help'],
        );
        self::assertSame($catalog['role.help'], $catalog['role.help_operclass']);
        self::assertSame('Permite administrar los roles de operadores, sus permisos y su configuración.', $catalog['role.short']);
        self::assertSame('ROLE <opción> [nombre] [...]', $catalog['role.syntax']);
        self::assertSame($catalog['role.syntax'], $catalog['role.syntax_operclass']);
        self::assertSame('Muestra los roles disponibles.', $catalog['role.list.short']);
        self::assertSame('Crea un nuevo rol.', $catalog['role.add.short']);
        self::assertSame('Elimina un rol.', $catalog['role.del.short']);
        self::assertSame('Gestiona los permisos del rol.', $catalog['role.perms.short']);
        self::assertSame('Configura los modos de usuario del rol.', $catalog['role.modes.short']);
        self::assertSame('Configura el patrón de host virtual del rol.', $catalog['role.vhost.short']);
        self::assertSame('Configura la clase de operador de UnrealIRCd.', $catalog['role.operclass.short']);
        self::assertSame('ROLE ADD <nombre> [descripción]', $catalog['role.add.syntax']);
        self::assertSame('ROLE PERMS {ADD|DEL|LIST|CLEAR} <rol> [parámetros]', $catalog['role.perms.syntax']);
        self::assertSame('ROLE MODES {VIEW|SET} <rol> [modos]', $catalog['role.modes.syntax']);
        self::assertSame('ROLE VHOST {VIEW|SET} <rol> [vhost]', $catalog['role.vhost.syntax']);
        self::assertSame('ROLE OPERCLASS {LIST|VIEW|SET} [rol] [parámetros]', $catalog['role.operclass.syntax']);
        self::assertSame(
            implode("\n", [
                'Permite configurar el host virtual que reciben los operadores de un rol',
                'al identificarse, sustituyendo su host personal.',
                '',
                'El host se genera combinando el apodo sin caracteres especiales con el',
                'patrón configurado. Por ejemplo:',
                '',
                '_davidlig_ + admin.ares → davidlig.admin.ares',
                '',
                "\x02 ■ Opciones:\x02",
                "    \x0310›\x03\x0F \x02\x0303VIEW      \x03\x0FMuestra el patrón configurado para un rol.",
                "    \x0310›\x03\x0F \x02\x0303SET       \x03\x0FEstablece o elimina el patrón de un rol.",
                '',
                'El patrón solo admite letras, números, guiones y puntos. Debe contener',
                'al menos un punto, no puede comenzar ni terminar con guion o punto,',
                'ni contener puntos consecutivos.',
                '',
                'Para eliminar el host forzado, utiliza SET sin indicar un patrón.',
                '',
                'Comando exclusivo para usuarios Root.',
            ]),
            $catalog['role.vhost.help'],
        );
        self::assertStringNotContainsString('OFF', $catalog['role.modes.syntax']);
        self::assertStringNotContainsString('OFF', $catalog['role.vhost.syntax']);
        self::assertStringNotContainsString('OFF', $catalog['role.operclass.syntax']);
        $modesHelp = $catalog['role.modes.help'] ?? null;
        self::assertIsString($modesHelp);
        self::assertStringContainsString('Omite los modos para eliminarlos todos.', $modesHelp);
        self::assertStringNotContainsString('OFF', $modesHelp);
        $permissionsHelp = $catalog['role.perms.help'] ?? null;
        if (is_string($permissionsHelp)) {
            self::assertStringContainsString("\x02\x0303CLEAR     \x03\x0FQuita todos los permisos del rol.", $permissionsHelp);
            self::assertStringContainsString("\x02\x0303DEL       \x03\x0FQuita un permiso del rol.", $permissionsHelp);
            self::assertStringNotContainsString('no para roles protegidos', $permissionsHelp);
        } else {
            self::fail('Missing OperServ role permissions help text.');
        }
        self::assertSame("%command_prefix%\x02\x0303ROLE LIST\x03\x0f", $catalog['role.example']);
        self::assertSame(
            "%command_prefix%\x02\x0303ROLE ADD SOPORTE Operadores de soporte\x03\x0f",
            $catalog['role.examples.0'],
        );
        self::assertSame(
            "%command_prefix%\x02\x0303ROLE ADD SOPORTE Operadores de soporte\x03\x0F",
            $catalog['role.add.example'],
        );
        self::assertSame(
            "%command_prefix%\x02\x0303ROLE PERMS LIST SOPORTE\x03\x0f",
            $catalog['role.perms.example'],
        );
        self::assertSame(
            "%command_prefix%\x02\x0303ROLE PERMS ADD SOPORTE operserv.gline\x03\x0f",
            $catalog['role.perms.examples.0'],
        );
        self::assertSame(
            "%command_prefix%\x02\x0303ROLE PERMS CLEAR SOPORTE\x03\x0f",
            $catalog['role.perms.examples.3'],
        );
        self::assertSame(
            "%command_prefix%\x02\x0303ROLE MODES VIEW OPER\x03\x0f",
            $catalog['role.modes.example'],
        );
        self::assertSame(
            "%command_prefix%\x02\x0303ROLE VHOST VIEW OPER\x03\x0f",
            $catalog['role.vhost.example'],
        );
        self::assertSame(
            "%command_prefix%\x02\x0303ROLE VHOST SET OPER admin.ares\x03\x0f",
            $catalog['role.vhost.examples.0'],
        );
        self::assertSame(
            "%command_prefix%\x02\x0303ROLE VHOST SET OPER\x03\x0f",
            $catalog['role.vhost.examples.1'],
        );
        self::assertArrayNotHasKey('role.vhost.examples.2', $catalog);
        self::assertSame(
            "%command_prefix%\x02\x0303ROLE OPERCLASS VIEW SOPORTE\x03\x0f",
            $catalog['role.operclass.examples.0'],
        );
    }

    #[Test]
    public function spanishOperServRoleModesHelpFormatsActionsAsOptions(): void
    {
        $catalog = self::flatten(self::parseDomain('operserv', 'es'));

        self::assertSame(
            implode("\n", [
                'Gestiona los modos de usuario IRCOP asignados a un rol.',
                '',
                "\x02 ■ Opciones:\x02",
                "    \x0310›\x03\x0F \x02\x0303VIEW      \x03\x0FMuestra los modos actuales del rol.",
                "    \x0310›\x03\x0F \x02\x0303SET       \x03\x0FEstablece los modos IRCOP.",
                '',
                'Omite los modos para eliminarlos todos.',
                '',
                'Los usuarios con este rol recibirán estos modos al identificarse.',
                'Modos disponibles según IRCD',
                'Este comando está restringido a usuarios Root.',
            ]),
            $catalog['role.modes.help'],
        );
    }

    #[Test]
    public function everyLocaleFormatsOperServRoleActionsAsCanonicalOptions(): void
    {
        foreach (self::LOCALES as $locale) {
            $catalog = self::flatten(self::parseDomain('operserv', $locale));

            foreach ([
                'perms' => ['LIST', 'ADD', 'DEL', 'CLEAR'],
                'modes' => ['VIEW', 'SET'],
                'vhost' => ['VIEW', 'SET'],
                'operclass' => ['LIST', 'VIEW', 'SET'],
            ] as $subcommand => $actions) {
                $key = 'role.' . $subcommand . '.help';
                $help = $catalog[$key];
                self::assertIsString($help, $locale . ':' . $key);
                self::assertIsString($catalog['help.options_header']);
                self::assertStringContainsString("\n\n" . $catalog['help.options_header'] . "\n", $help, $locale . ':' . $key);

                foreach ($actions as $action) {
                    self::assertSame(
                        1,
                        substr_count($help, "    \x0310›\x03\x0F \x02\x0303" . str_pad($action, 10) . "\x03\x0F"),
                        $locale . ':' . $key . ':' . $action,
                    );
                }

                foreach (explode("\n", $help) as $line) {
                    $visible = preg_replace('/[\x02\x0F]|\x03(?:[0-9]{1,2}(?:,[0-9]{1,2})?)?/', '', $line);
                    self::assertIsString($visible);
                    self::assertLessThanOrEqual(80, mb_strlen($visible), $locale . ':' . $key . ':' . $visible);
                }
            }
        }
    }

    #[Test]
    public function everyLocaleKeepsRoleModesHelpGenericWithoutRemovingCommandExamples(): void
    {
        $availableModes = [
            'ca' => "Modes disponibles segons l'IRCd",
            'de' => 'Verfügbare Modi nach IRCD',
            'el' => 'Διαθέσιμες λειτουργίες ανά IRCD',
            'en' => 'Available modes by IRCd',
            'es' => 'Modos disponibles según IRCD',
            'eu' => 'IRCd bakoitzeko moduak',
            'fr' => 'Modes disponibles selon l’IRCd',
            'gl' => 'Modos dispoñibles segundo o IRCD',
            'it' => 'Modalità disponibili per IRCD',
            'nl' => 'Beschikbare modi per IRCD',
            'pl' => 'Dostępne tryby zależnie od IRCD',
            'pt' => 'Modos disponíveis por IRCD',
            'ro' => 'Moduri disponibile per IRCD',
            'tr' => 'IRCD’ye göre kullanılabilir modlar',
        ];
        $setDescriptions = [
            'ca' => 'Estableix els modes IRCOP.',
            'de' => 'Legt IRCOP-Modi fest.',
            'el' => 'Ορίζει λειτουργίες IRCOP.',
            'en' => 'Sets IRCOP modes.',
            'es' => 'Establece los modos IRCOP.',
            'eu' => 'IRCOP moduak ezartzen ditu.',
            'fr' => 'Définit les modes IRCOP.',
            'gl' => 'Establece os modos IRCOP.',
            'it' => 'Imposta le modalità IRCOP.',
            'nl' => 'Stelt IRCOP-modi in.',
            'pl' => 'Ustawia tryby IRCOP.',
            'pt' => 'Define os modos IRCOP.',
            'ro' => 'Setează modurile IRCOP.',
            'tr' => 'IRCOP modlarını ayarlar.',
        ];

        foreach (self::LOCALES as $locale) {
            $catalog = self::flatten(self::parseDomain('operserv', $locale));
            $help = $catalog['role.modes.help'];
            self::assertIsString($help);
            self::assertContains($availableModes[$locale], explode("\n", $help), $locale);
            self::assertStringContainsString("\x02\x0303SET       \x03\x0F" . $setDescriptions[$locale], $help, $locale);
            self::assertStringNotContainsString('UnrealIRCd', $help, $locale);
            self::assertStringNotContainsString('InspIRCd', $help, $locale);
            self::assertStringNotContainsString('oH', $help, $locale);

            $example = $catalog['role.modes.examples.0'];
            self::assertIsString($example);
            self::assertStringContainsString('ROLE MODES SET OPER oH', $example, $locale);
            self::assertStringStartsWith('%command_prefix%', $example, $locale);
        }
    }

    #[Test]
    public function spanishOperServRoleOperclassHelpFormatsActionsAsOptions(): void
    {
        $catalog = self::flatten(self::parseDomain('operserv', 'es'));

        self::assertSame(
            implode("\n", [
                'Permite consultar y configurar la operclass de un rol.',
                '',
                "\x02 ■ Opciones:\x02",
                "    \x0310›\x03\x0F \x02\x0303LIST      \x03\x0FMuestra las operclasses disponibles en la red.",
                "    \x0310›\x03\x0F \x02\x0303VIEW      \x03\x0FMuestra la operclass del rol y las disponibles.",
                "    \x0310›\x03\x0F \x02\x0303SET       \x03\x0FAsigna la operclass a los IRCOP al identificarse.",
                '',
                'Omite el valor para eliminarla.',
                '',
                'Disponible solo en protocolos UnrealIRCd.',
            ]),
            $catalog['role.operclass.help'],
        );
    }

    #[Test]
    public function spanishOperServRoleVhostHasThreeExamplesWithoutDuplicates(): void
    {
        $catalog = self::flatten(self::parseDomain('operserv', 'es'));
        $exampleKeys = array_values(array_filter(
            array_keys($catalog),
            static fn (string $key): bool => str_starts_with($key, 'role.vhost.examples.'),
        ));

        self::assertSame(['role.vhost.examples.0', 'role.vhost.examples.1'], $exampleKeys);
    }

    #[Test]
    public function spanishOperServRolePermissionDescriptionsCoverEveryRegisteredPermission(): void
    {
        $permissionDescriptions = [
            'chanserv' => [
                'clearaccess.short',
                'clearusers.short',
                'drop.short',
                'drop.force.short',
                'forbid.short',
                'history.short',
                'ircoponly.short',
                'level_founder.short',
                'list.short',
                'noexpire.short',
                'restore.short',
                'suspend.short',
            ],
            'nickserv' => [
                'userip.short',
                'whoip.short',
                'suspend.short',
                'rename.short',
                'drop.short',
                'drop.force.short',
                'restore.short',
                'forbid.short',
                'forbidvhost.short',
                'saset.short',
                'noexpire.short',
                'history.short',
                'list.short',
                'regmode.short',
            ],
            'operserv' => [
                'badmail.short',
                'kill.short',
                'gline.short',
                'global.short',
                'motd.short',
                'raw.short',
            ],
        ];

        foreach ($permissionDescriptions as $domain => $keys) {
            $catalog = self::flatten(self::parseDomain($domain, 'es'));
            foreach ($keys as $key) {
                self::assertIsString($catalog[$key] ?? null, $domain . '.' . $key);
            }
        }

        $catalog = self::flatten(self::parseDomain('operserv', 'es'));
        self::assertSame([], array_filter(
            array_keys($catalog),
            static fn (string $key): bool => str_starts_with($key, 'role.perms.descriptions.'),
        ));
        self::assertSame("\x0303✓\x03\x0F", $catalog['role.perms.list.assigned']);
        self::assertSame("\x0304✗\x03\x0F", $catalog['role.perms.list.unassigned']);
        self::assertSame("\x0303Lista de permisos de \x02%role%\x02\x03", $catalog['role.perms.list.header']);
        self::assertSame('Asignado | Clave permiso | Descripción', $catalog['role.perms.list.columns']);
        self::assertSame('%assigned% | %permission% | %description%', $catalog['role.perms.list.row']);
        $permissionsHelp = $catalog['role.perms.help'] ?? null;
        if (is_string($permissionsHelp)) {
            self::assertStringContainsString("\x02\x0303LIST      \x03\x0FMuestra todos los permisos disponibles", $permissionsHelp);
        } else {
            self::fail('Missing OperServ role permissions help text.');
        }
    }

    /**
     * @param array<array-key, mixed> $source
     * @param array<array-key, mixed> $actual
     */
    private static function assertSourceCatalogContract(array $source, array $actual, string $path): void
    {
        self::assertSame(array_keys($source), array_keys($actual), $path . ' must preserve source mapping/list structure.');

        foreach ($source as $key => $expected) {
            $label = $path . '.' . $key;
            $value = $actual[$key];
            if (is_array($expected)) {
                self::assertIsArray($value, $label);
                self::assertSourceCatalogContract($expected, $value, $label);

                continue;
            }

            self::assertIsString($expected, $label);
            self::assertIsString($value, $label);

            preg_match_all('/%[a-z_]+%/', $expected, $expectedPlaceholders);
            preg_match_all('/%[a-z_]+%/', $value, $actualPlaceholders);
            sort($expectedPlaceholders[0]);
            sort($actualPlaceholders[0]);
            self::assertSame($expectedPlaceholders[0], $actualPlaceholders[0], $label . ' must preserve placeholder multiplicities.');

            $controlPattern = '/\x03(?:[0-9]{1,2}(?:,[0-9]{1,2})?)?|[\x02\x0f\x16\x1d\x1f]/';
            preg_match_all($controlPattern, $expected, $expectedControls);
            preg_match_all($controlPattern, $value, $actualControls);
            sort($expectedControls[0]);
            sort($actualControls[0]);
            if ('nickserv.el.suspend.help' === $label) {
                // Double-quoted YAML now decodes the IRC color escapes that were previously literal in this block scalar.
                $greekSuspendControls = ["\x03", "\x03", "\x03", "\x03", "\x0303", "\x0303", "\x0303", "\x0303"];
                sort($greekSuspendControls);
                self::assertSame($greekSuspendControls, $actualControls[0], $label . ' must preserve IRC colors around duration values.');
            } else {
                self::assertSame($expectedControls[0], $actualControls[0], $label . ' must preserve control multiplicities.');
            }

            if (1 === preg_match('/(?:^|[._])(?:native_)?examples?\.(?:\d+)$|(?:^|[._])(?:native_)?example$/', $label)) {
                self::assertStringNotContainsString("\n", $value, $label . ' examples must remain single-line values.');
            }

            if (str_ends_with($label, '.help') || str_ends_with($label, '.help.intro')) {
                foreach (explode("\n", $value) as $line) {
                    $visible = preg_replace('/\x03(?:[0-9]{1,2}(?:,[0-9]{1,2})?)?|[\x02\x0f\x16\x1d\x1f]/', '', $line);
                    self::assertIsString($visible);
                    self::assertLessThanOrEqual(80, mb_strlen($visible), $label . ' HELP prose must fit in 80 visible characters.');
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private static function parseDomain(string $domain, string $locale): array
    {
        $parsed = TranslationCatalog::parseFile(sprintf('%s/translations/%s.%s.yaml', self::ROOT, $domain, $locale));

        $normalized = [];
        foreach ($parsed as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /** @return array<string, mixed> Common keys take precedence over service-local keys. */
    private static function effectiveServiceCatalog(string $serviceDomain, string $locale): array
    {
        return self::flatten(self::parseDomain('common', $locale))
            + self::flatten(self::parseDomain($serviceDomain, $locale));
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
