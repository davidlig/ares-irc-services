<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\User;

use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\User\IrcRescueTokenNotifier;
use App\NickServ\Application\Model\UserMessagePreference;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(IrcRescueTokenNotifier::class)]
final class IrcRescueTokenNotifierTest extends TestCase
{
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
