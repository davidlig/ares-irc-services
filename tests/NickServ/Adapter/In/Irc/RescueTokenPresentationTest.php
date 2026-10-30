<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\NickServ\Adapter\In\Irc\RescueTokenPresentation;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;

use function dirname;

#[CoversClass(RescueTokenPresentation::class)]
final class RescueTokenPresentationTest extends TestCase
{
    #[Test]
    public function rendersIssuedAndOptionalRecoveryTokensWithPrivateCommands(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/nickserv.en.yaml', 'en', 'nickserv');
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $issued = RescueTokenPresentation::render($translator, $renderer, 'rescue.issued', 'en', 'TOKEN123', 'CustomNick', null);
        $optional = RescueTokenPresentation::render($translator, $renderer, 'rescue.optional_replacement', 'en', 'TOKEN123', 'CustomNick', Role::Information);
        self::assertStringContainsString('TOKEN123', $issued);
        self::assertStringContainsString('IMPORTANT:', $issued);
        self::assertStringContainsString('/msg CustomNick', $issued);
        self::assertStringContainsString('VERIFY TOKEN123', $issued);
        self::assertStringContainsString('VERIFY TOKEN123', $optional);
        self::assertStringNotContainsString('%token', $issued . $optional);
    }
}
