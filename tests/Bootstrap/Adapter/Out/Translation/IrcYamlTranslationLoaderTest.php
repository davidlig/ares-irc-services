<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Adapter\Out\Translation;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\Exception\InvalidResourceException;
use Symfony\Component\Translation\Translator;

use function file_put_contents;
use function glob;
use function sys_get_temp_dir;
use function tempnam;

use const PHP_INT_SIZE;

#[CoversClass(IrcYamlTranslationLoader::class)]
final class IrcYamlTranslationLoaderTest extends TestCase
{
    private string $resource;

    #[Test]
    public function decodesOnlyTaggedControlsAndPreservesCatalogMetadata(): void
    {
        file_put_contents($this->resource, <<<'YAML'
            group:
              help: !irc |-
                \x02Options\x02
                    \x0310›\x03\x0f LIST

                %value% \x41 \n \x20
              plain: |-
                \x02literal\x02
              old: "\x0303existing\x03"
            constant: !php/const PHP_INT_SIZE
            YAML);
        $catalogue = new IrcYamlTranslationLoader()->load($this->resource, 'es', 'nickserv');
        self::assertSame('es', $catalogue->getLocale());
        self::assertSame("\x02Options\x02\n    \x0310›\x03\x0f LIST\n\n%value% \\x41 \\n \\x20", $catalogue->get('group.help', 'nickserv'));
        self::assertSame('\\x02literal\\x02', $catalogue->get('group.plain', 'nickserv'));
        self::assertSame("\x0303existing\x03", $catalogue->get('group.old', 'nickserv'));
        self::assertSame((string) PHP_INT_SIZE, $catalogue->get('constant', 'nickserv'));
        self::assertCount(1, $catalogue->getResources());
        self::assertSame($this->resource, (string) $catalogue->getResources()[0]);
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', $this->resource, 'es', 'nickserv');
        self::assertStringContainsString('\\x03user\\x0F', $translator->trans('group.help', ['%value%' => '\\x03user\\x0F'], 'nickserv'));
    }

    #[Test]
    public function coldAndWarmTranslationCachesPreserveTaggedBytes(): void
    {
        file_put_contents($this->resource, "help: !irc |-\n  \\x02Options\\x02\n      LIST\n");
        for ($run = 0; $run < 2; ++$run) {
            $translator = new Translator('en', null, $this->resource . '-cache', false);
            $translator->addLoader('yaml', new IrcYamlTranslationLoader());
            $translator->addResource('yaml', $this->resource, 'en');
            self::assertSame("\x02Options\x02\n    LIST", $translator->trans('help'));
        }
        self::assertNotEmpty(glob($this->resource . '-cache/*'));
    }

    #[Test]
    public function preservesEmptyCatalogs(): void
    {
        file_put_contents($this->resource, '');
        self::assertSame([], new IrcYamlTranslationLoader()->load($this->resource, 'en')->all('messages'));
    }

    #[Test]
    #[DataProvider('invalidCatalogs')]
    public function rejectsInvalidCatalogs(string $yaml): void
    {
        file_put_contents($this->resource, $yaml);
        $this->expectException(InvalidResourceException::class);
        new IrcYamlTranslationLoader()->load($this->resource, 'en');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCatalogs(): iterable
    {
        yield 'malformed YAML' => ['message: ['];
        yield 'scalar root' => ['hello'];
        yield 'unsupported tag' => ['message: !unknown value'];
        yield 'tagged sequence' => ['message: !irc [value]'];
        yield 'tagged mapping' => ['message: !irc {key: value}'];
        yield 'nested invalid tag' => ['group: {message: !unknown value}'];
    }

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'irc-yaml-test-');
        self::assertIsString($file);
        $this->resource = $file;
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->resource, $this->resource . '-cache']);
    }
}
