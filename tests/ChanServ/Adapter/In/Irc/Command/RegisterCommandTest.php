<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\RegisterCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ChanServ REGISTER no longer registers anything: it answers with a notice that
 * redirects to CReG. The registration flow itself is covered by
 * RegisterChannelHandlerTest.
 */
#[CoversClass(RegisterCommand::class)]
final class RegisterCommandTest extends TestCase
{
    /**
     * @param list<string> $args
     */
    private function createContext(
        ?SenderView $sender,
        ?ChanAccountView $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ChannelLookupPort $channelLookup,
    ): ChanServContext {
        return new ChanServContext(
            $sender,
            $senderAccount,
            'REGISTER',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    #[Test]
    public function usesLevelFounderReturnsFalse(): void
    {
        self::assertFalse(new RegisterCommand()->usesLevelFounder());
    }

    #[Test]
    public function repliesWithTheDisabledNoticeForAWellFormedRequest(): void
    {
        $messages = [];

        new RegisterCommand()->execute($this->disabledContext(
            $messages,
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', true, false),
            new ChanAccountView(1, 'User', 'en'),
            ['#test', 'Description'],
        ));

        self::assertSame(['register.disabled'], $messages);
    }

    #[Test]
    public function repliesWithTheDisabledNoticeEvenWhenTheChannelArgumentIsInvalid(): void
    {
        $messages = [];

        new RegisterCommand()->execute($this->disabledContext(
            $messages,
            new SenderView('UID1', 'User', 'i', 'h', 'c', 'ip', true, false),
            new ChanAccountView(1, 'User', 'en'),
            ['notachannel', 'Description'],
        ));

        self::assertSame(['register.disabled'], $messages);
    }

    #[Test]
    public function repliesWithTheDisabledNoticeEvenWhenTheSenderIsNotIdentified(): void
    {
        $messages = [];

        new RegisterCommand()->execute($this->disabledContext($messages, null, null, ['#test', 'Description']));

        self::assertSame(['register.disabled'], $messages);
    }

    /**
     * Context that records every translated key so the reply can be asserted.
     *
     * @param list<string> $messages
     * @param list<string> $args
     */
    private function disabledContext(
        array &$messages,
        ?SenderView $sender,
        ?ChanAccountView $senderAccount,
        array $args,
    ): ChanServContext {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id) use (&$messages): string {
            $messages[] = $id;

            return $id;
        });

        return $this->createContext(
            $sender,
            $senderAccount,
            $args,
            $this->createStub(ChanServNotifierInterface::class),
            $translator,
            $this->createStub(ChannelLookupPort::class),
        );
    }

    #[Test]
    public function getNameReturnsRegister(): void
    {
        self::assertSame('REGISTER', new RegisterCommand()->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        self::assertSame([], new RegisterCommand()->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsZero(): void
    {
        self::assertSame(0, new RegisterCommand()->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsRegisterSyntax(): void
    {
        self::assertSame('register.syntax', new RegisterCommand()->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsRegisterHelp(): void
    {
        self::assertSame('register.help', new RegisterCommand()->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsOne(): void
    {
        self::assertSame(1, new RegisterCommand()->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsRegisterShort(): void
    {
        self::assertSame('register.short', new RegisterCommand()->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        self::assertSame([], new RegisterCommand()->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        self::assertFalse(new RegisterCommand()->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsNull(): void
    {
        self::assertNull(new RegisterCommand()->getRequiredPermission());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsFalse(): void
    {
        self::assertFalse(new RegisterCommand()->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        self::assertFalse(new RegisterCommand()->allowsForbiddenChannel());
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $provider1 = new class('nickserv', 'NickServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider2 = new class('chanserv', 'ChanServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider3 = new class('memoserv', 'MemoServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };
        $provider4 = new class('operserv', 'OperServ') implements ServiceNicknameProviderInterface {
            public function __construct(private string $key, private string $nick) {}

            public function getServiceKey(): string
            {
                return $this->key;
            }

            public function getNickname(): string
            {
                return $this->nick;
            }
        };

        return new ServiceNicknameRegistry([$provider1, $provider2, $provider3, $provider4]);
    }
}
