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

    /** @return iterable<string, array{UnbanChannelOutcome, string, bool}> */
    public static function outcomes(): iterable
    {
        yield 'removed' => [UnbanChannelOutcome::Removed, 'unban.done', true];
        yield 'empty' => [UnbanChannelOutcome::NoMatches, 'unban.no_matches', true];
        yield 'unavailable' => [UnbanChannelOutcome::Unavailable, 'unban.unavailable', false];
        yield 'absent' => [UnbanChannelOutcome::ChannelNotOnNetwork, 'unban.not_on_network', false];
    }

    #[DataProvider('outcomes')]
    public function testPresentationAndSelfOnlyTypedInput(UnbanChannelOutcome $outcome, string $message, bool $success): void
    {
        $handler = $this->createMock(UnbanChannelHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(static fn (UnbanChannel $input): bool => '#test' === $input->channelName && 1 === $input->accountId && '001AAA' === $input->requesterUid && !$input->founderEquivalent))->willReturn(new UnbanChannelResult($outcome, 2));
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')->with('001AAA', $message, 'NOTICE');
        $command = new UnbanCommand($handler);
        self::assertSame($success, $command->execute($this->createContext(new SenderView('001AAA', 'Alice', 'i', 'h', 'c', '*', true), new ChanAccountView(1, 'Alice', 'en'), ['#test'], $notifier, $this->translator()))->success);
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
