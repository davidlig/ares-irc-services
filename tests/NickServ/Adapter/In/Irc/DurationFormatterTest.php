<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\NickServ\Adapter\In\Irc\DurationFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

use function dirname;
use function is_string;
use function strtr;
use function trim;

#[CoversClass(DurationFormatter::class)]
final class DurationFormatterTest extends TestCase
{
    #[Test]
    #[DataProvider('spanishDurations')]
    public function formatsSecondsUsingLocaleUnitTranslations(int $seconds, string $expected): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/common.es.yaml', 'es', 'common');
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/nickserv.es.yaml', 'es', 'nickserv');

        $translate = static function (string $key, array $params = []) use ($translator): string {
            $domain = $translator->getCatalogue('es')->defines($key, 'common') ? 'common' : 'nickserv';

            return $translator->trans($key, self::wrapParams($params), $domain, 'es');
        };

        self::assertSame($expected, DurationFormatter::format($seconds, $translate));
    }

    /** @return iterable<string, array{int, string}> */
    public static function spanishDurations(): iterable
    {
        yield 'zero uses plural seconds' => [0, '0 segundos'];
        yield 'singular second' => [1, '1 segundo'];
        yield 'plural seconds' => [59, '59 segundos'];
        yield 'singular minute' => [60, '1 minuto'];
        yield 'plural minutes' => [300, '5 minutos'];
        yield 'singular hour' => [3600, '1 hora'];
        yield 'singular day' => [86400, '1 día'];
        yield 'singular fixed month' => [2592000, '1 mes'];
        yield 'singular fixed year' => [31536000, '1 año'];
        yield 'all units with remainder' => [31536000 + 2592000 + 86400 + 3600 + 60 + 1, '1 año, 1 mes, 1 día, 1 hora, 1 minuto y 1 segundo'];
        yield 'compound minutes and seconds' => [65, '1 minuto y 5 segundos'];
    }

    #[Test]
    public function usesOnlyTheProvidedLocaleKeysForUnitAndListWording(): void
    {
        $translations = [
            'duration.unit.year.singular' => 'year',
            'duration.unit.year.plural' => 'years',
            'duration.unit.second.singular' => 'second',
            'duration.unit.second.plural' => 'seconds',
            'duration.list_separator' => ' / ',
            'duration.list_join' => '%prefix% plus %last%',
        ];
        $requestedKeys = [];
        $translate = static function (string $key, array $params = []) use ($translations, &$requestedKeys): string {
            $requestedKeys[] = $key;

            return strtr($translations[$key] ?? $key, self::wrapParams($params));
        };

        self::assertSame('2 years plus 3 seconds', DurationFormatter::format(2 * 31_536_000 + 3, $translate));
        self::assertContains('duration.unit.year.plural', $requestedKeys);
        self::assertContains('duration.unit.second.plural', $requestedKeys);
        self::assertContains('duration.list_join', $requestedKeys);
        self::assertContains('duration.list_separator', $requestedKeys);
    }

    #[Test]
    public function rejectsNegativeDurations(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DurationFormatter::format(-1, static fn (string $key): string => $key);
    }

    /**
     * @param array<mixed, mixed> $params
     *
     * @return array<string, string>
     */
    private static function wrapParams(array $params): array
    {
        $wrapped = [];
        foreach ($params as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new InvalidArgumentException('Translation parameters must have string keys and values.');
            }

            $wrapped['%' . trim($key, '%') . '%'] = $value;
        }

        return $wrapped;
    }
}
