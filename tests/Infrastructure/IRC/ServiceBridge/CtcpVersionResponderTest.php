<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\IRC\ServiceBridge\CtcpVersionResponder;
use App\Infrastructure\NickServ\UserLanguageResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(CtcpVersionResponder::class)]
final class CtcpVersionResponderTest extends TestCase
{
    private CtcpVersionResponder $responder;

    private function createTranslator(string $returnValue = ''): TranslatorInterface
    {
        return new class($returnValue) implements TranslatorInterface {
            public function __construct(private string $return)
            {
            }

            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $this->return;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }

    private function createLanguageResolver(string $defaultLanguage = 'en'): UserLanguageResolver
    {
        return new UserLanguageResolver(
            $this->createStub(RegisteredNickRepositoryInterface::class),
            $defaultLanguage,
        );
    }

    protected function setUp(): void
    {
        $this->responder = new CtcpVersionResponder(
            $this->createTranslator(),
            $this->createLanguageResolver(),
        );
    }

    #[Test]
    public function getVersionResponseReturnsExpectedString(): void
    {
        self::assertSame('Ares IRC Services v1.0', $this->responder->getVersionResponse());
    }

    #[Test]
    public function getAsciiArtLinesReturnsNonEmptyArray(): void
    {
        $lines = $this->responder->getAsciiArtLines('en');

        self::assertIsArray($lines);
        self::assertNotEmpty($lines);
    }

    #[Test]
    public function getAsciiArtLinesContainsAresText(): void
    {
        $lines = $this->responder->getAsciiArtLines('en');
        $combined = implode("\n", $lines);

        self::assertStringContainsString('@@@', $combined);
    }

    #[Test]
    public function getAsciiArtLinesContainsSignature(): void
    {
        $lines = $this->responder->getAsciiArtLines('en');
        $combined = implode("\n", $lines);

        self::assertStringContainsString('Ares 2011-2023', $combined);
    }

    #[Test]
    public function getAsciiArtLinesContainsRedHeartInSignature(): void
    {
        $lines = $this->responder->getAsciiArtLines('en');

        $foundRedHeart = false;
        foreach ($lines as $line) {
            if (str_contains($line, "\x034") && str_contains($line, '❤')) {
                $foundRedHeart = true;
                break;
            }
        }

        self::assertTrue($foundRedHeart, 'Expected to find red heart (\\x034❤) in signature');
    }

    #[Test]
    public function getAsciiArtLinesIncludesTranslatedTribute(): void
    {
        $spanishTribute = "Para mi leal y eterno amigo,\nLlenaste mi casa de vida.";
        $responder = new CtcpVersionResponder(
            $this->createTranslator($spanishTribute),
            $this->createLanguageResolver(),
        );

        $lines = $responder->getAsciiArtLines('es');

        $combined = implode("\n", $lines);
        self::assertStringContainsString('Para mi leal y eterno amigo', $combined);
    }

    #[Test]
    public function getAsciiArtLinesUnescapesIrcCodes(): void
    {
        $tributeWithEscapedCodes = '\x02Bold text\x02 normal';
        $responder = new CtcpVersionResponder(
            $this->createTranslator($tributeWithEscapedCodes),
            $this->createLanguageResolver(),
        );

        $lines = $responder->getAsciiArtLines('en');

        $foundBoldCode = false;
        foreach ($lines as $line) {
            if (str_contains($line, "\x02")) {
                $foundBoldCode = true;
                break;
            }
        }

        self::assertTrue($foundBoldCode, 'Expected IRC codes to be unescaped');
    }

    #[Test]
    public function getAsciiArtLinesContainsEmptyLines(): void
    {
        $lines = $this->responder->getAsciiArtLines('en');

        $blankLines = array_filter($lines, static fn (string $line): bool => ' ' === $line);

        self::assertNotEmpty($blankLines);
    }
}
