<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\User\IrcRescueTokenNotifier;
use App\NickServ\Application\Model\UserMessagePreference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(IrcRescueTokenNotifier::class)]
final class IrcRescueTokenNotifierTest extends TestCase
{
    public function testRealCatalogDeliversTheAgreedNoticeWithConfiguredNicknameAndMessagePreference(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', __DIR__ . '/../../../../../translations/nickserv.es.yaml', 'es', 'nickserv');
        $expected = "Token de rescate: \x0303example-rescue-token\x03\n\n"
            . "Guarda este token en un lugar seguro. Lo necesitarás para recuperar tu apodo si olvidas tu contraseña.\n\n"
            . "Para confirmarlo, utiliza: /msg IdentityKeeper \x0303VERIFY example-rescue-token\x03\n\n"
            . "\x0304⚠\x03 IMPORTANTE: Solo el último token generado es válido. Una vez confirmado, no volverá a mostrarse. No lo compartas con nadie.";

        foreach ([[UserMessagePreference::Notice, 'NOTICE'], [UserMessagePreference::PrivateMessage, 'PRIVMSG']] as [$preference, $type]) {
            $notifier = $this->createMock(NickServNotifierInterface::class);
            $notifier->method('getNick')->willReturn('IdentityKeeper');
            $notifier->expects(self::once())->method('sendMessage')->with('UID1', $expected, $type);

            new IrcRescueTokenNotifier($notifier, $translator)->issued('UID1', 'example-rescue-token', 'es', $preference);
        }
    }

    public function testPrivateDeliveryUsesAccountLanguageAndMessagePreference(): void
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
