<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\UnbanCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChanServAccessHelper;
use App\ChanServ\Application\UseCase\UnbanUser\UnbanUser;
use App\ChanServ\Application\UseCase\UnbanUser\UnbanUserHandler;
use App\ChanServ\Application\UseCase\UnbanUser\UnbanUserResult;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\ChanServ\Domain\Policy\BanMaskMatcher;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(UnbanCommand::class)]
#[CoversClass(UnbanUser::class)]
#[CoversClass(UnbanUserHandler::class)]
#[CoversClass(UnbanUserResult::class)]
#[CoversClass(BanMaskMatcher::class)]
final class UnbanCommandTest extends TestCase
{
    #[Test]
    public function literalMaskRemovesExactlyThatEntry(): void
    {
        $messages = [];
        $modes = [];
        $view = new ChannelView('#test', '+nt', null, 1, [], 0, [], ['*!*@bad.host', 'User!ident@*']);
        $cmd = $this->createCommand($this->createChannelRepository(), $this->createChannelLookup($view));
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test', '*!*@bad.host'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
        );

        $outcome = $cmd->execute($context);

        self::assertTrue($outcome->success);
        self::assertSame(['unban.done'], $messages);
        self::assertSame([['#test', '-b', ['*!*@bad.host'], 1700000000]], $modes);
    }

    #[Test]
    public function nicknameArgumentRemovesEveryMatchingBan(): void
    {
        $messages = [];
        $modes = [];
        $lookup = $this->createStub(NetworkUserLookupPort::class);
        $lookup->method('findByNick')->willReturn(new SenderView('UID2', 'Target', 'tident', 'thost', 'tcloaked', 'ip'));
        $view = new ChannelView('#test', '+nt', null, 1, [], 0, [], ['*!tident@*', '*!*@thost', '*!*@elsewhere']);
        $cmd = $this->createCommand($this->createChannelRepository(), $this->createChannelLookup($view));
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test', 'Target'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
            $lookup,
        );

        $outcome = $cmd->execute($context);

        self::assertTrue($outcome->success);
        self::assertSame(['unban.done'], $messages);
        self::assertSame([
            ['#test', '-b', ['*!tident@*'], 1700000000],
            ['#test', '-b', ['*!*@thost'], 1700000000],
        ], $modes);
    }

    #[Test]
    public function omittedArgumentRemovesEveryBanMatchingTheSender(): void
    {
        $messages = [];
        $modes = [];
        $view = new ChannelView('#test', '+nt', null, 1, [], 0, [], ['*!ident@*', 'User!*@*', '*!*@other.host', '*!*@host']);
        $cmd = $this->createCommand($this->createChannelRepository(), $this->createChannelLookup($view));
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
        );

        $outcome = $cmd->execute($context);

        self::assertTrue($outcome->success);
        self::assertSame([
            ['#test', '-b', ['*!ident@*'], 1700000000],
            ['#test', '-b', ['User!*@*'], 1700000000],
            ['#test', '-b', ['*!*@host'], 1700000000],
        ], $modes);
    }

    #[Test]
    public function doneReplyCarriesTheRemovedCountAndTarget(): void
    {
        $messages = [];
        $modes = [];
        $params = [];
        $view = new ChannelView('#test', '+nt', null, 1, [], 0, [], ['*!ident@*', 'User!*@*']);
        $cmd = $this->createCommand($this->createChannelRepository(), $this->createChannelLookup($view));
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static function (string $id, array $parameters = []) use (&$messages, &$params): string {
            $messages[] = $id;
            $params[$id] = $parameters;

            return $id;
        });
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test'],
            $this->createNotifier($messages, $modes),
            $translator,
        );

        $cmd->execute($context);

        self::assertSame('2', $params['unban.done']['%count%']);
        self::assertSame('User', $params['unban.done']['%target%']);
    }

    #[Test]
    public function noMatchingBansRepliesNoBansAndAppliesNoMode(): void
    {
        $messages = [];
        $modes = [];
        $view = new ChannelView('#test', '+nt', null, 1, [], 0, [], ['*!*@other.host']);
        $cmd = $this->createCommand($this->createChannelRepository(), $this->createChannelLookup($view));
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
        );

        $outcome = $cmd->execute($context);

        self::assertFalse($outcome->success);
        self::assertSame(['unban.no_bans'], $messages);
        self::assertSame([], $modes);
    }

    #[Test]
    public function missingChannelViewFallsBackToTheExactTargetMask(): void
    {
        $messages = [];
        $modes = [];
        $lookup = $this->createStub(NetworkUserLookupPort::class);
        $lookup->method('findByNick')->willReturn(new SenderView('UID2', 'Target', 'tident', 'thost', 'tcloaked', 'ip'));
        $cmd = $this->createCommand($this->createChannelRepository());
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test', 'Target'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
            $lookup,
        );

        $outcome = $cmd->execute($context);

        self::assertTrue($outcome->success);
        self::assertSame(['unban.done'], $messages);
        self::assertSame([['#test', '-b', ['Target!tident@thost'], 1700000000]], $modes);
    }

    #[Test]
    public function missingChannelViewFallsBackToTheSendersMask(): void
    {
        $messages = [];
        $modes = [];
        $cmd = $this->createCommand($this->createChannelRepository());
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
        );

        $outcome = $cmd->execute($context);

        self::assertTrue($outcome->success);
        self::assertSame([['#test', '-b', ['User!ident@host'], 1700000000]], $modes);
    }

    #[Test]
    public function emptyArgumentUsesTheSendersOwnMask(): void
    {
        $messages = [];
        $modes = [];
        $cmd = $this->createCommand($this->createChannelRepository());
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test', ''],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
        );

        $cmd->execute($context);

        self::assertSame([['#test', '-b', ['User!ident@host'], 1700000000]], $modes);
    }

    #[Test]
    public function unknownNicknameReportsUserNotFoundAndAppliesNoMode(): void
    {
        $messages = [];
        $modes = [];
        $lookup = $this->createStub(NetworkUserLookupPort::class);
        $lookup->method('findByNick')->willReturn(null);
        $cmd = $this->createCommand($this->createChannelRepository());
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test', 'Ghost'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
            $lookup,
        );

        $outcome = $cmd->execute($context);

        self::assertFalse($outcome->success);
        self::assertSame(['unban.user_not_found'], $messages);
        self::assertSame([], $modes);
    }

    #[Test]
    public function invalidChannelIsRejected(): void
    {
        $messages = [];
        $modes = [];
        $cmd = $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class));
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['x'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
        );

        $outcome = $cmd->execute($context);

        self::assertFalse($outcome->success);
        self::assertSame(['error.invalid_channel'], $messages);
    }

    #[Test]
    public function unidentifiedSenderIsRejected(): void
    {
        $messages = [];
        $modes = [];
        $cmd = $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class));
        $context = $this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            null,
            ['#test'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
        );

        $outcome = $cmd->execute($context);

        self::assertFalse($outcome->success);
        self::assertSame(['error.not_identified'], $messages);
    }

    #[Test]
    public function missingSenderIsRejected(): void
    {
        $messages = [];
        $modes = [];
        $cmd = $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class));
        $context = $this->createContext(
            null,
            new ChanAccountView(2, 'User', 'en'),
            ['#test'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
        );

        $outcome = $cmd->execute($context);

        self::assertFalse($outcome->success);
        self::assertSame([], $messages);
    }

    #[Test]
    public function unregisteredChannelThrows(): void
    {
        $messages = [];
        $modes = [];
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn(null);
        $cmd = $this->createCommand($channels);

        $this->expectException(ChannelNotRegisteredException::class);
        $cmd->execute($this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test', '*!*@bad.host'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
        ));
    }

    #[Test]
    public function insufficientLevelThrows(): void
    {
        $messages = [];
        $modes = [];
        $levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepository->method('findByChannelAndKey')->willReturn(null);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(99);
        $accessRepository = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepository->method('findByChannelAndNick')->willReturn($access);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);
        $cmd = new UnbanCommand(
            new UnbanUserHandler(
                $channels,
                new ChanServAccessHelper($accessRepository, $levelRepository),
            ),
            $this->createStub(ChannelLookupPort::class),
            new BanMaskMatcher(),
        );

        $this->expectException(InsufficientAccessException::class);
        $cmd->execute($this->createContext(
            new SenderView('UID1', 'User', 'ident', 'host', 'cloaked', 'ip'),
            new ChanAccountView(2, 'User', 'en'),
            ['#test', '*!*@bad.host'],
            $this->createNotifier($messages, $modes),
            $this->createTranslator(),
        ));
    }

    #[Test]
    public function getNameReturnsUnban(): void
    {
        self::assertSame('UNBAN', $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->getName());
    }

    #[Test]
    public function getAliasesReturnsEmptyArray(): void
    {
        self::assertSame([], $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->getAliases());
    }

    #[Test]
    public function getMinArgsReturnsOne(): void
    {
        self::assertSame(1, $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->getMinArgs());
    }

    #[Test]
    public function getSyntaxKeyReturnsUnbanSyntax(): void
    {
        self::assertSame('unban.syntax', $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->getSyntaxKey());
    }

    #[Test]
    public function getHelpKeyReturnsUnbanHelp(): void
    {
        self::assertSame('unban.help', $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->getHelpKey());
    }

    #[Test]
    public function getOrderReturnsEleven(): void
    {
        self::assertSame(11, $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->getOrder());
    }

    #[Test]
    public function getShortDescKeyReturnsUnbanShort(): void
    {
        self::assertSame('unban.short', $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->getShortDescKey());
    }

    #[Test]
    public function getSubCommandHelpReturnsEmptyArray(): void
    {
        self::assertSame([], $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->getSubCommandHelp());
    }

    #[Test]
    public function isOperOnlyReturnsFalse(): void
    {
        self::assertFalse($this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->isOperOnly());
    }

    #[Test]
    public function getRequiredPermissionReturnsIdentified(): void
    {
        self::assertSame('IDENTIFIED', $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->getRequiredPermission());
    }

    #[Test]
    public function getExampleKeyReturnsTheUnbanExampleKey(): void
    {
        self::assertSame('unban.example', $this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->getExampleKey());
    }

    #[Test]
    public function allowsSuspendedChannelReturnsFalse(): void
    {
        self::assertFalse($this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->allowsSuspendedChannel());
    }

    #[Test]
    public function allowsForbiddenChannelReturnsFalse(): void
    {
        self::assertFalse($this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->allowsForbiddenChannel());
    }

    #[Test]
    public function usesLevelFounderReturnsTrue(): void
    {
        self::assertTrue($this->createCommand($this->createStub(RegisteredChannelRepositoryInterface::class))->usesLevelFounder());
    }

    private function createCommand(
        RegisteredChannelRepositoryInterface $channels,
        ?ChannelLookupPort $channelLookup = null,
    ): UnbanCommand {
        $levelRepository = $this->createStub(ChannelLevelRepositoryInterface::class);
        $levelRepository->method('findByChannelAndKey')->willReturn(null);
        $access = $this->createStub(ChannelAccess::class);
        $access->method('getLevel')->willReturn(100);
        $accessRepository = $this->createStub(ChannelAccessRepositoryInterface::class);
        $accessRepository->method('findByChannelAndNick')->willReturn($access);

        return new UnbanCommand(
            new UnbanUserHandler(
                $channels,
                new ChanServAccessHelper($accessRepository, $levelRepository),
            ),
            $channelLookup ?? $this->createStub(ChannelLookupPort::class),
            new BanMaskMatcher(),
        );
    }

    private function createChannelRepository(): RegisteredChannelRepositoryInterface
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('isFounder')->willReturn(false);
        $channel->method('getCreatedAt')->willReturn(new DateTimeImmutable('@1700000000'));
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($channel);

        return $channels;
    }

    private function createChannelLookup(ChannelView $view): ChannelLookupPort
    {
        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn($view);

        return $lookup;
    }

    /**
     * @param list<string>                                                $messages
     * @param list<array{0: string, 1: string, 2: list<string>, 3: ?int}> $modes
     */
    private function createNotifier(array &$messages, array &$modes): ChanServNotifierInterface
    {
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $notifier->method('setChannelModes')->willReturnCallback(static function (string $channel, string $modeString, array $params, ?int $timestamp) use (&$modes): void {
            $modes[] = [$channel, $modeString, $params, $timestamp];
        });

        return $notifier;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return $translator;
    }

    /** @param array<string> $args */
    private function createContext(
        ?SenderView $sender,
        ?ChanAccountView $senderAccount,
        array $args,
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        ?NetworkUserLookupPort $userLookup = null,
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
            $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            $this->createServiceNicks(),
        );
    }

    private function createServiceNicks(): ServiceNicknameRegistry
    {
        $providers = [];
        foreach (['nickserv' => 'NickServ', 'chanserv' => 'ChanServ', 'memoserv' => 'MemoServ', 'operserv' => 'OperServ'] as $key => $nick) {
            $providers[] = new class($key, $nick) implements ServiceNicknameProviderInterface {
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
        }

        return new ServiceNicknameRegistry($providers);
    }
}
