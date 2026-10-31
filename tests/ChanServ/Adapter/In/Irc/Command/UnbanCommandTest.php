<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\UnbanCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\UseCase\Unban\UnbanChannel;
use App\ChanServ\Application\UseCase\Unban\UnbanChannelHandlerInterface;
use App\ChanServ\Application\UseCase\Unban\UnbanChannelOutcome;
use App\ChanServ\Application\UseCase\Unban\UnbanChannelResult;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(UnbanCommand::class)]
final class UnbanCommandTest extends TestCase
{
    public function testMetadataAndExtraArgumentsRejectedWithoutCallingHandler(): void
    {
        $handler = $this->createMock(UnbanChannelHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new UnbanCommand($handler);
        self::assertSame('UNBAN', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('unban.syntax', $command->getSyntaxKey());
        self::assertSame('unban.help', $command->getHelpKey());
        self::assertSame('unban.example', $command->getExampleKey());
        self::assertSame('unban.short', $command->getShortDescKey());
        self::assertSame(11, $command->getOrder());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertSame('IDENTIFIED', $command->getRequiredPermission());
        self::assertFalse($command->isOperOnly());
        self::assertFalse($command->allowsForbiddenChannel());
        self::assertFalse($command->allowsSuspendedChannel());
        self::assertTrue($command->usesLevelFounder());
        foreach ([[], ['#test', 'Bob'], ['#test', '*!*@*'], ['invalid']] as $args) {
            $notifier = $this->createMock(ChanServNotifierInterface::class);
            $notifier->expects(self::once())->method('sendMessage');
            self::assertFalse($command->execute($this->createContext(new SenderView('001AAA', 'Alice', 'i', 'h', 'c', '*', true), new ChanAccountView(1, 'Alice', 'en'), $args, $notifier, $this->translator()))->success);
        }
    }

    public function testMissingAndUnidentifiedSenderRejectsWithoutEffects(): void
    {
        $handler = $this->createMock(UnbanChannelHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $command = new UnbanCommand($handler);
        foreach ([[null, new ChanAccountView(1, 'Alice', 'en')], [new SenderView('001AAA', 'Alice', 'i', 'h', 'c', '*', true), null], [new SenderView('001AAA', 'Alice', 'i', 'h', 'c', '*'), new ChanAccountView(1, 'Alice', 'en')]] as [$sender, $account]) {
            $notifier = $this->createMock(ChanServNotifierInterface::class);
            $notifier->expects(null === $sender ? self::never() : self::once())->method('sendMessage');
            self::assertFalse($command->execute($this->createContext($sender, $account, ['#test'], $notifier, $this->translator()))->success);
        }
    }

    /** @return iterable<string, array{UnbanChannelOutcome, string, bool, string|null}> */
    public static function outcomes(): iterable
    {
        yield 'removed' => [UnbanChannelOutcome::Removed, "\x0303✔\x03\x0F unban.done", true, "\x0310ℹ\x03\x0F unban.notice_channel"];
        yield 'empty' => [UnbanChannelOutcome::NoMatches, "\x0310ℹ\x03\x0F unban.no_matches", true, null];
        yield 'unavailable' => [UnbanChannelOutcome::Unavailable, "\x0304✗\x03\x0F unban.unavailable", false, null];
        yield 'absent' => [UnbanChannelOutcome::ChannelNotOnNetwork, "\x0304✗\x03\x0F unban.not_on_network", false, null];
    }

    #[DataProvider('outcomes')]
    public function testPresentationAndSelfOnlyTypedInput(UnbanChannelOutcome $outcome, string $message, bool $success, ?string $channelNotice): void
    {
        $handler = $this->createMock(UnbanChannelHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(static fn (UnbanChannel $input): bool => '#test' === $input->channelName && 1 === $input->accountId && '001AAA' === $input->requesterUid && !$input->founderEquivalent))->willReturn(new UnbanChannelResult($outcome, 2));
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('001AAA', $message, 'NOTICE');
        if (null !== $channelNotice) {
            $notifier->expects(self::once())->method('sendNoticeToChannel')->with('#test', $channelNotice);
        } else {
            $notifier->expects(self::never())->method('sendNoticeToChannel');
        }
        $command = new UnbanCommand($handler);
        self::assertSame($success, $command->execute($this->createContext(new SenderView('001AAA', 'Alice', 'i', 'h', 'c', '*', true), new ChanAccountView(1, 'Alice', 'en'), ['#test'], $notifier, $this->translator()))->success);
    }

    public function testInvalidChannelUsesLocalizedEmphasizedExample(): void
    {
        $handler = $this->createMock(UnbanChannelHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => match ($key) {
            'error.invalid_channel' => 'Nombre no válido. Usa %example%.',
            'error.invalid_channel_example' => '#canal',
            default => $key,
        });
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('001AAA', "\x0304✗\x03\x0F Nombre no válido. Usa \x02#canal\x03\x0F.", 'NOTICE');
        $context = $this->createContext(new SenderView('001AAA', 'Alice', 'i', 'h', 'c', '*', true), new ChanAccountView(1, 'Alice', 'en'), ['invalid'], $notifier, $translator);

        self::assertFalse(new UnbanCommand($handler)->execute($context)->success);
    }

    public function testLocalizedTemplateInterpolatesParametersWithoutReparsing(): void
    {
        $handler = $this->createStub(UnbanChannelHandlerInterface::class);
        $handler->method('handle')->willReturn(new UnbanChannelResult(UnbanChannelOutcome::Removed, 2));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => match ($key) {
            'unban.notice_channel' => '%nickname% ha utilizado UNBAN.',
            default => 'Canal %channel%: retirados %count%',
        });
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('001AAA', "\x0303✔\x03\x0F Canal #%count%: retirados 2", 'NOTICE');
        $notifier->expects(self::once())->method('sendNoticeToChannel')->with('#%count%', "\x0310ℹ\x03\x0F \x0302Alice\x03\x0F ha utilizado UNBAN.");
        $context = $this->createContext(new SenderView('001AAA', 'Alice', 'i', 'h', 'c', '*', true), new ChanAccountView(1, 'Alice', 'en'), ['#%count%'], $notifier, $translator);

        self::assertTrue(new UnbanCommand($handler)->execute($context)->success);
    }

    public function testInjectedStyleControlsTheSameLocalizedResponse(): void
    {
        $handler = $this->createStub(UnbanChannelHandlerInterface::class);
        $handler->method('handle')->willReturn(new UnbanChannelResult(UnbanChannelOutcome::Removed, 1));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => match ($key) {
            'unban.notice_channel' => '%nickname% used UNBAN.',
            default => 'Removed %count% from %channel%',
        });
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('001AAA', 'OK Removed 1 from #test', 'NOTICE');
        $notifier->expects(self::once())->method('sendNoticeToChannel')->with('#test', 'INFO Alice used UNBAN.');
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: [Role::Success->value => 'OK', Role::Information->value => 'INFO'])));
        $context = $this->createContext(new SenderView('001AAA', 'Alice', 'i', 'h', 'c', '*', true), new ChanAccountView(1, 'Alice', 'en'), ['#test'], $notifier, $translator, $renderer);

        self::assertTrue(new UnbanCommand($handler)->execute($context)->success);
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        return $translator;
    }

    /** @param array<string> $args */
    private function createContext(
        ?SenderView $sender,
        ?ChanAccountView $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ?IrcRenderer $renderer = null,
    ): ChanServContext {
        return new ChanServContext(
            $sender,
            $senderAccount,
            'UNBAN',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
            renderer: $renderer,
        );
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
