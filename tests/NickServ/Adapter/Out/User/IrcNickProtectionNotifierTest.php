<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\User\IrcNickProtectionNotifier;
use App\NickServ\Application\Model\UserMessagePreference;
use App\Shared\Application\Port\TranslationInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcNickProtectionNotifier::class)]
#[CoversClass(UserMessagePreference::class)]
final class IrcNickProtectionNotifierTest extends TestCase
{
    #[Test]
    public function presentsForbiddenNicknameAsANotice(): void
    {
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturn('forbidden');
        $notifier = $this->createMock(NickServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('UID1', 'forbidden', 'NOTICE');

        new IrcNickProtectionNotifier($notifier, $translator)->notifyForbidden('UID1', 'Alice', 'reason', 'es');
    }

    #[Test]
    public function presentsRenameMessagesUsingTheAccountPreference(): void
    {
        $translator = $this->createStub(TranslationInterface::class);
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
