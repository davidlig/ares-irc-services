<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Translation\Translator;

#[CoversNothing]
final class TranslationLoaderWiringTest extends KernelTestCase
{
    public function testBothRuntimeYamlAliasesResolveTaggedMessages(): void
    {
        self::bootKernel();
        $loader = self::getContainer()->get('translation.loader.yml');
        self::assertInstanceOf(IrcYamlTranslationLoader::class, $loader);
        self::assertSame($loader, self::getContainer()->get(IrcYamlTranslationLoader::class));
        $translator = self::getContainer()->get('translator.default');
        self::assertInstanceOf(Translator::class, $translator);
        $resource = tempnam(sys_get_temp_dir(), 'irc-wiring-');
        self::assertIsString($resource);
        // The compiled translator defers resources until initialization; avoid existing locale caches.
        $locale = 'es_XA_' . substr(hash('sha256', $resource), 0, 8);
        try {
            file_put_contents($resource, "message: !irc |-\n  \\x02Options\\x02\n      LIST %value%\n");
            foreach (['yaml', 'yml'] as $format) {
                $domain = 'irc_wiring_' . $format;
                $translator->addResource($format, $resource, $locale, $domain);
            }
            foreach (['yaml', 'yml'] as $format) {
                $domain = 'irc_wiring_' . $format;
                self::assertSame("\x02Options\x02\n    LIST \\x03user", $translator->trans('message', ['%value%' => '\\x03user'], $domain, $locale));
            }
        } finally {
            unlink($resource);
        }
    }
}
