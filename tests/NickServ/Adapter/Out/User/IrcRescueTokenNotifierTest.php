<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\User\IrcRescueTokenNotifier;
use App\NickServ\Application\Model\UserMessagePreference;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function dirname;
use function substr_count;

#[CoversClass(IrcRescueTokenNotifier::class)]
final class IrcRescueTokenNotifierTest extends TestCase
{
    #[Test]
    public function realCatalogDeliversTheAgreedNoticeWithConfiguredNicknameAndMessagePreference(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', __DIR__ . '/../../../../../translations/nickserv.es.yaml', 'es', 'nickserv');
        $expected = "Token de rescate: \x02\x0303example-rescue-token\x03\x0F\n\n"
            . "Guarda este token en un lugar seguro. Lo necesitarás para recuperar tu apodo si olvidas tu contraseña.\n\n"
            . "Para confirmarlo, utiliza: /msg IdentityKeeper \x02\x0303VERIFY example-rescue-token\x03\x0F\n\n"
            . "\x0304⚠\x03\x0F IMPORTANTE: Solo el último token generado es válido. Una vez confirmado, no volverá a mostrarse. No lo compartas con nadie.";

        foreach ([[UserMessagePreference::Notice, 'NOTICE'], [UserMessagePreference::PrivateMessage, 'PRIVMSG']] as [$preference, $type]) {
            $notifier = $this->createMock(NickServNotifierInterface::class);
            $notifier->method('getNick')->willReturn('IdentityKeeper');
            $notifier->expects(self::once())->method('sendMessage')->with('UID1', $expected, $type);

            $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
            new IrcRescueTokenNotifier($notifier, $translator, $renderer)->issued('UID1', 'example-rescue-token', 'es', $preference);
        }
    }

    #[Test]
    public function tokenTextIsNeverRecursivelyInterpolated(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        new IrcRescueTokenNotifier($notifier, $translator, $renderer)->issued('UID1', 'token%bot%literal', 'es', UserMessagePreference::Notice);
        self::assertSame(2, substr_count($messages[0], 'token%bot%literal'));
    }

    #[Test]
    public function fallbackDeliveryDoesNotLeakTemplateSlots(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('IdentityKeeper');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        new IrcRescueTokenNotifier($notifier, $translator)->issued('UID1', 'token-value', 'es', UserMessagePreference::Notice);
        self::assertStringContainsString('token-value', $messages[0]);
        self::assertStringContainsString('VERIFY token-value', $messages[0]);
        self::assertStringNotContainsString('%warning%', $messages[0]);
    }

    #[Test]
    public function privateDeliveryUsesAccountLanguageAndMessagePreference(): void
    {
        foreach ([[UserMessagePreference::Notice, 'NOTICE'], [UserMessagePreference::PrivateMessage, 'PRIVMSG']] as [$preference, $type]) {
            $notifier = $this->createMock(NickServNotifierInterface::class);
            $notifier->method('getNick')->willReturn('NickServ');
            $notifier->expects(self::once())->method('sendMessage')->with('UID1', 'private token instructions', $type);
            $translator = $this->createMock(TranslatorInterface::class);
            $translator->expects(self::once())->method('trans')->with('rescue.issued', ['%token%' => 'uuid', '%bot%' => 'NickServ'], 'nickserv', 'es')->willReturn('private token instructions');
            new IrcRescueTokenNotifier($notifier, $translator)->issued('UID1', 'uuid', 'es', $preference);
        }
    }
}
