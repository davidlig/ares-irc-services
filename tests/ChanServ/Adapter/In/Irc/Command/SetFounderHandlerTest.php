<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\Application\Port\EventBusInterface;
use App\Application\Port\TranslationInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\SetFounderHandler;
use App\ChanServ\Adapter\Out\InMemory\FounderChangeTokenRegistry;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAccessRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\FounderChangeMailSender;
use App\ChanServ\Application\Port\Out\FounderChangeTokenGenerator;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelFounderChangedEvent;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\Out\ServiceNicknameProviderInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function is_string;

#[CoversClass(SetFounderHandler::class)]
final class SetFounderHandlerTest extends TestCase
{
    /**
     * @param string[] $args
     */
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslationInterface $translator,
        array $args,
        string $senderNick = 'Founder',
        bool $isLevelFounder = false,
        string $ipBase64 = 'ip',
        bool $withoutSender = false,
    ): ChanServContext {
        return new ChanServContext(
            $withoutSender ? null : new SenderView('UID1', $senderNick, 'i', 'h', 'c', $ipBase64),
            null,
            'SET',
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
            $isLevelFounder,
        );
    }

    #[Test]
    public function emptyValueRepliesSyntax(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', '']), $channel, '   ');

        self::assertSame(['set.founder.syntax'], $messages);
    }

    #[Test]
    public function nickNotRegisteredRepliesError(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::once())->method('findAccountByNick')->with('Nobody')->willReturn(null);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Nobody']), $channel, 'Nobody');

        self::assertSame(['error.nick_not_registered'], $messages);
    }

    #[Test]
    public function suspendedNickRepliesSuspended(): void
    {
        $newAccount = new ChanAccountView(20, 'Suspended', 'en', suspended: true);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::once())->method('findAccountByNick')->with('Suspended')->willReturn($newAccount);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Suspended']), $channel, 'Suspended');

        self::assertSame(['set.founder.suspended'], $messages);
    }

    #[Test]
    public function notRegisteredStatusRepliesMustBeRegistered(): void
    {
        $newAccount = new ChanAccountView(20, 'Pending', 'en', registered: false);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::once())->method('findAccountByNick')->with('Pending')->willReturn($newAccount);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Pending']), $channel, 'Pending');

        self::assertSame(['set.founder.must_be_registered'], $messages);
    }

    #[Test]
    public function sameAsFounderRepliesCannotBeSelf(): void
    {
        $newAccount = new ChanAccountView(10, 'Founder', 'en');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::once())->method('findAccountByNick')->with('Founder')->willReturn($newAccount);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Founder']), $channel, 'Founder');

        self::assertSame(['set.founder.cannot_be_self'], $messages);
    }

    #[Test]
    public function newFounderIsSuccessorRepliesCannotBeSuccessor(): void
    {
        $newAccount = new ChanAccountView(20, 'Successor', 'en');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(20);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::once())->method('findAccountByNick')->with('Successor')->willReturn($newAccount);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Successor']), $channel, 'Successor');

        self::assertSame(['set.founder.cannot_be_successor'], $messages);
    }

    #[Test]
    public function founderLimitExceededRepliesLimitExceeded(): void
    {
        $newAccount = new ChanAccountView(20, 'Busy', 'en');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('findByFounderNickId')->with(20)->willReturn([
            $this->createStub(RegisteredChannel::class),
            $this->createStub(RegisteredChannel::class),
            $this->createStub(RegisteredChannel::class),
        ]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::once())->method('findAccountByNick')->with('Busy')->willReturn($newAccount);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
            3600,
            600,
            3,
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Busy']), $channel, 'Busy');

        self::assertSame(['set.founder.limit_exceeded'], $messages);
    }

    #[Test]
    public function currentFounderHasNoEmailRepliesNoEmail(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: '');
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder']), $channel, 'NewFounder');

        self::assertSame(['set.founder.no_email'], $messages);
    }

    #[Test]
    public function invalidTokenRepliesInvalidToken(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountById')->with(10)->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder', 'wrong-token']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.invalid_token'], $messages);
    }

    #[Test]
    public function validTokenWithMissingSenderStopsBeforeTransfer(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);

        $tokens = new FounderChangeTokenRegistry();
        $tokens->store(1, 20, 'valid-token', new DateTimeImmutable('+1 hour'));
        $nickRepository = $this->createStub(ChanUserAccountPort::class);
        $nickRepository->method('findAccountByNick')->willReturn($newAccount);
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $nickRepository->method('findAccountById')->willReturn($currentFounder);
        $channelRepository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByFounderNickId')->willReturn([]);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepository,
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $nickRepository,
            $tokens,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder', 'valid-token'], withoutSender: true), $channel, 'NewFounder');

        self::assertSame([], $messages);
    }

    #[Test]
    public function validTokenChangesFounderRemovesAccessDispatchesEventAndReplies(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(1);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getFounderNickId')->willReturn(10);
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channel->expects(self::atLeastOnce())->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $existingAccess = $this->createStub(ChannelAccess::class);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(1, 20)->willReturn($existingAccess);
        $accessRepo->expects(self::once())->method('remove')->with($existingAccess);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountById')->willReturnMap([[10, $currentFounder], [20, $newAccount]]);
        $registry = new FounderChangeTokenRegistry();
        $registry->store(1, 20, 'valid-token', new DateTimeImmutable()->modify('+1 hour'));
        $dispatched = null;
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $e) use (&$dispatched): bool {
                if ($e instanceof ChannelFounderChangedEvent) {
                    $dispatched = $e;

                    return '#test' === $e->channelName;
                }

                return false;
            }))
            ->willReturnArgument(0);
        $messages = [];
        $channelNotices = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (string $ch, string $m) use (&$channelNotices): void {
            $channelNotices[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $eventDispatcher,
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder', 'valid-token']),
            $channel,
            'NewFounder',
        );

        self::assertInstanceOf(ChannelFounderChangedEvent::class, $dispatched);
        self::assertSame(['set.founder.updated'], $messages);
        self::assertCount(1, $channelNotices);
    }

    #[Test]
    public function validTokenWithWildcardIpDispatchesEventWithStarIp(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(1);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getFounderNickId')->willReturn(10);
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channel->expects(self::atLeastOnce())->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $existingAccess = $this->createStub(ChannelAccess::class);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(1, 20)->willReturn($existingAccess);
        $accessRepo->expects(self::once())->method('remove')->with($existingAccess);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountById')->willReturnMap([[10, $currentFounder], [20, $newAccount]]);
        $registry = new FounderChangeTokenRegistry();
        $registry->store(1, 20, 'valid-token', new DateTimeImmutable()->modify('+1 hour'));
        $dispatchedIp = '';
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (ChannelFounderChangedEvent $e) use (&$dispatchedIp): ChannelFounderChangedEvent {
            $dispatchedIp = $e->performedByIp;

            return $e;
        });
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (): void {});
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $eventDispatcher,
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder', 'valid-token'], ipBase64: '*'),
            $channel,
            'NewFounder',
        );

        self::assertSame('*', $dispatchedIp);
    }

    #[Test]
    public function validTokenWithInvalidBase64IpDispatchesEventWithRawIp(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(1);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getFounderNickId')->willReturn(10);
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channel->expects(self::atLeastOnce())->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $existingAccess = $this->createStub(ChannelAccess::class);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(1, 20)->willReturn($existingAccess);
        $accessRepo->expects(self::once())->method('remove')->with($existingAccess);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountById')->willReturnMap([[10, $currentFounder], [20, $newAccount]]);
        $registry = new FounderChangeTokenRegistry();
        $registry->store(1, 20, 'valid-token', new DateTimeImmutable()->modify('+1 hour'));
        $dispatchedIp = '';
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())->method('dispatch')->willReturnCallback(static function (ChannelFounderChangedEvent $e) use (&$dispatchedIp): ChannelFounderChangedEvent {
            $dispatchedIp = $e->performedByIp;

            return $e;
        });
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (): void {});
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $eventDispatcher,
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder', 'valid-token'], ipBase64: '!!!invalid!!!'),
            $channel,
            'NewFounder',
        );

        self::assertSame('!!!invalid!!!', $dispatchedIp);
    }

    #[Test]
    public function requestTokenSendsEmailAndRepliesTokenSent(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $mailSender = $this->createMock(FounderChangeMailSender::class);
        $mailSender->expects(self::once())->method('sendFounderChangeToken');

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $mailSender,
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.token_sent'], $messages);
    }

    #[Test]
    public function requestTokenThrottledWhenMinIntervalNotElapsed(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $registry->recordRequest(1);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
            3600,
            600,
            3,
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.throttled'], $messages);
    }

    #[Test]
    public function consumeTokenCannotBeSameAsSelf(): void
    {
        $newAccount = new ChanAccountView(10, 'Someone', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $registry->store(1, 10, 'my-token', new DateTimeImmutable()->modify('+1 hour'));
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Someone', 'my-token']),
            $channel,
            'Someone',
        );

        self::assertSame(['set.founder.cannot_be_self'], $messages);
    }

    #[Test]
    public function consumeTokenCannotBeSuccessor(): void
    {
        $newAccount = new ChanAccountView(20, 'Successor', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(20);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $registry->store(1, 20, 'my-token', new DateTimeImmutable()->modify('+1 hour'));
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Successor', 'my-token']),
            $channel,
            'Successor',
        );

        self::assertSame(['set.founder.cannot_be_successor'], $messages);
    }

    #[Test]
    public function validTokenWithoutExistingAccessDoesNotRemove(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(1);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getFounderNickId')->willReturn(10);
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channel->expects(self::atLeastOnce())->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(1, 20)->willReturn(null);
        $accessRepo->expects(self::never())->method('remove');
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountById')->willReturnMap([[10, $currentFounder], [20, $newAccount]]);
        $registry = new FounderChangeTokenRegistry();
        $registry->store(1, 20, 'valid-token', new DateTimeImmutable()->modify('+1 hour'));
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder', 'valid-token']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.updated'], $messages);
    }

    #[Test]
    public function founderNotFoundErrorFallbackToId(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(1);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getFounderNickId')->willReturn(10);
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channel->expects(self::atLeastOnce())->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(1, 20)->willReturn(null);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::atLeastOnce())->method('findAccountById')->willReturnMap([[10, $currentFounder], [20, null]]);
        $registry = new FounderChangeTokenRegistry();
        $registry->store(1, 20, 'valid-token', new DateTimeImmutable()->modify('+1 hour'));
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder', 'valid-token']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.updated'], $messages);
    }

    #[Test]
    public function requestTokenFailsOnMailError(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $mailSender = $this->createMock(FounderChangeMailSender::class);
        $mailSender->expects(self::once())->method('sendFounderChangeToken')->willThrowException(new RuntimeException('Mail failure'));

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $mailSender,
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['error.mail_failed'], $messages);
    }

    #[Test]
    public function consumeTokenWhenStoredFounderIdIsCurrentFounderRepliesCannotBeSelf(): void
    {
        $newAccount = new ChanAccountView(30, 'OtherUser', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $registry->store(1, 10, 'stored-token', new DateTimeImmutable()->modify('+1 hour'));
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'OtherUser', 'stored-token']),
            $channel,
            'OtherUser',
        );

        self::assertSame(['set.founder.cannot_be_self'], $messages);
    }

    #[Test]
    public function consumeTokenWhenStoredFounderIdIsSuccessorRepliesCannotBeSuccessor(): void
    {
        $newAccount = new ChanAccountView(30, 'OtherUser', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'founder@example.com');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(20);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $registry->store(1, 20, 'stored-token', new DateTimeImmutable()->modify('+1 hour'));
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'OtherUser', 'stored-token']),
            $channel,
            'OtherUser',
        );

        self::assertSame(['set.founder.cannot_be_successor'], $messages);
    }

    #[Test]
    public function shortEmailPrefixMasksEmailAsAsterisks(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'a@short.com');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . (is_string($params['%email_hint%'] ?? null) ? $params['%email_hint%'] : ''));
        $mailSender = $this->createMock(FounderChangeMailSender::class);
        $mailSender->expects(self::once())->method('sendFounderChangeToken');

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $mailSender,
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.token_sent***@***'], $messages);
    }

    #[Test]
    public function emailWithAtAtPositionZeroMasksEmailAsAsterisks(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: '@nodomain.com');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . (is_string($params['%email_hint%'] ?? null) ? $params['%email_hint%'] : ''));
        $mailSender = $this->createMock(FounderChangeMailSender::class);
        $mailSender->expects(self::once())->method('sendFounderChangeToken');

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $mailSender,
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.token_sent***@***'], $messages);
    }

    #[Test]
    public function emailWithNoAtSignMasksEmailAsAsterisks(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $currentFounder = new ChanAccountView(10, 'Founder', 'en', email: 'noemailatall');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(ChanUserAccountPort::class);
        $nickRepo->method('findAccountByNick')->willReturn($newAccount);
        $nickRepo->method('findAccountById')->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id, array $params = []): string => $id . (is_string($params['%email_hint%'] ?? null) ? $params['%email_hint%'] : ''));
        $mailSender = $this->createMock(FounderChangeMailSender::class);
        $mailSender->expects(self::once())->method('sendFounderChangeToken');

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventBusInterface::class),
            $this->createStub(FounderChangeTokenGenerator::class),
            $mailSender,
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.token_sent***@***'], $messages);
    }

    #[Test]
    public function directTransferChangesFounderWhenIsLevelFounder(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(1);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getFounderNickId')->willReturn(10);
        $channel->expects(self::atLeastOnce())->method('getSuccessorNickId')->willReturn(null);
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(1, 20)->willReturn(null);
        $accessRepo->expects(self::never())->method('remove');
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::once())->method('findAccountByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::once())->method('findAccountById')->with(20)->willReturn($newAccount);
        $dispatched = null;
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static function (object $e) use (&$dispatched): bool {
                if ($e instanceof ChannelFounderChangedEvent) {
                    $dispatched = $e;

                    return '#test' === $e->channelName;
                }

                return false;
            }))
            ->willReturnArgument(0);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $channelNotices = [];
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (string $ch, string $m) use (&$channelNotices): void {
            $channelNotices[] = $m;
        });
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $eventDispatcher,
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder'], 'Founder', true),
            $channel,
            'NewFounder',
        );

        self::assertInstanceOf(ChannelFounderChangedEvent::class, $dispatched);
        self::assertSame(['set.founder.updated'], $messages);
        self::assertCount(1, $channelNotices);
    }

    #[Test]
    public function directTransferReturnsWithoutChangingFounderWhenSenderIsNull(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepository = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepository->method('findByFounderNickId')->willReturn([]);
        $channelRepository->expects(self::never())->method('save');
        $nickRepository = $this->createMock(ChanUserAccountPort::class);
        $nickRepository->expects(self::once())->method('findAccountByNick')->with('NewFounder')->willReturn($newAccount);
        $eventDispatcher = $this->createMock(EventBusInterface::class);
        $eventDispatcher->expects(self::never())->method('dispatch');
        $notifier = $this->createMock(ChanServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');
        $translator = $this->createStub(TranslationInterface::class);

        $handler = new SetFounderHandler(
            $channelRepository,
            $this->createStub(ChannelAccessRepositoryInterface::class),
            $nickRepository,
            new FounderChangeTokenRegistry(),
            $eventDispatcher,
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder'], isLevelFounder: true, withoutSender: true),
            $channel,
            'NewFounder',
        );
    }

    #[Test]
    public function directTransferRemovesExistingAccessEntry(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(1);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getFounderNickId')->willReturn(10);
        $channel->expects(self::atLeastOnce())->method('getSuccessorNickId')->willReturn(null);
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $existingAccess = $this->createStub(ChannelAccess::class);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(1, 20)->willReturn($existingAccess);
        $accessRepo->expects(self::once())->method('remove')->with($existingAccess);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::once())->method('findAccountByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::once())->method('findAccountById')->with(20)->willReturn($newAccount);
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $eventDispatcher,
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder'], 'Founder', true),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.updated'], $messages);
    }

    #[Test]
    public function directTransferUsesFallbackIdWhenNickNotFound(): void
    {
        $newAccount = new ChanAccountView(20, 'NewFounder', 'en');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::atLeastOnce())->method('getId')->willReturn(1);
        $channel->expects(self::atLeastOnce())->method('getName')->willReturn('#test');
        $channel->expects(self::atLeastOnce())->method('getFounderNickId')->willReturn(10);
        $channel->expects(self::atLeastOnce())->method('getSuccessorNickId')->willReturn(null);
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(1, 20)->willReturn(null);
        $nickRepo = $this->createMock(ChanUserAccountPort::class);
        $nickRepo->expects(self::once())->method('findAccountByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::once())->method('findAccountById')->with(20)->willReturn(null);
        $eventDispatcher = $this->createStub(EventBusInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $notifier->method('sendNoticeToChannel')->willReturnCallback(static function (): void {});
        $translator = $this->createStub(TranslationInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $eventDispatcher,
            $this->createStub(FounderChangeTokenGenerator::class),
            $this->createStub(FounderChangeMailSender::class),
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder'], 'Founder', true),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.updated'], $messages);
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
