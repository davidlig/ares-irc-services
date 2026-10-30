<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\User\IrcNickProtectionNotifier;
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

#[CoversClass(IrcNickProtectionNotifier::class)]
#[CoversClass(UserMessagePreference::class)]
final class IrcNickProtectionNotifierTest extends TestCase
{
    #[Test]
    public function protectionNoticesUseSemanticRolesAndConfiguredNick(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('CustomNick');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message, string $type) use (&$messages): void { $messages[] = [$message, $type]; });
        $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        $adapter = new IrcNickProtectionNotifier($notifier, $translator, $renderer);
        $adapter->notifyForbidden('UID1', 'Alice', 'reason', 'es');
        $adapter->notifyRename('UID1', 'Alice', 'Guest', 'es', UserMessagePreference::PrivateMessage);
        self::assertSame('NOTICE', $messages[0][1]);
        self::assertStringStartsWith("\x0304✗\x03\x0F ", $messages[0][0]);
        self::assertStringContainsString("\x02Alice\x03\x0F", $messages[0][0]);
        self::assertSame('PRIVMSG', $messages[1][1]);
        self::assertStringStartsWith("\x0307⚠\x03\x0F ", $messages[1][0]);
        self::assertStringContainsString("/msg CustomNick \x02\x0303IDENTIFY Alice <contraseña>\x03\x0F", $messages[2][0]);
    }

    #[Test]
    public function fallbackRenameDoesNotLeakCommandSlots(): void
    {
        $translator = new Translator('es');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/nickserv.es.yaml', 'es', 'nickserv');
        $messages = [];
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
        new IrcNickProtectionNotifier($notifier, $translator)->notifyRename('UID1', 'Alice', 'Guest', 'es', UserMessagePreference::Notice);
        self::assertStringNotContainsString('%identify%', $messages[1]);
        self::assertStringContainsString('IDENTIFY Alice <contraseña>', $messages[1]);
    }

    #[Test]
    public function presentsForbiddenNicknameAsANotice(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('forbidden');
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('UID1', 'forbidden', 'NOTICE');

        new IrcNickProtectionNotifier($notifier, $translator)->notifyForbidden('UID1', 'Alice', 'reason', 'es');
    }

    #[Test]
    public function presentsRenameMessagesUsingTheAccountPreference(): void
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnOnConsecutiveCalls('in-use', 'renamed', 'in-use', 'renamed');
        $sentMessageTypes = [];
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->expects(self::exactly(4))->method('sendMessage')->willReturnCallback(
            static function (string $uid, string $message, string $messageType) use (&$sentMessageTypes): void {
                $sentMessageTypes[] = $messageType;
            },
        );
        $adapter = new IrcNickProtectionNotifier($notifier, $translator);

        $adapter->notifyRename('UID1', 'Alice', 'Guest-ABC1234', 'en', UserMessagePreference::Notice);
        $adapter->notifyRename('UID1', 'Alice', 'Guest-ABC1234', 'en', UserMessagePreference::PrivateMessage);
        self::assertSame(['NOTICE', 'NOTICE', 'PRIVMSG', 'PRIVMSG'], $sentMessageTypes);
    }
}
