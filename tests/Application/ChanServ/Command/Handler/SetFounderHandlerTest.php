<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\SetFounderHandler;
use App\Application\ChanServ\Event\ChannelFounderChangedEvent;
use App\Application\ChanServ\FounderChangeTokenRegistry;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\ChannelAccess;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\ChannelAccessRepositoryInterface;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
#[CoversClass(SetFounderHandler::class)]
final class SetFounderHandlerTest extends TestCase
{
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        array $args,
        string $senderNick = 'Founder',
    ): ChanServContext {
        return new ChanServContext(
            new SenderView('UID1', $senderNick, 'i', 'h', 'c', 'ip'),
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
        );
    }

    #[Test]
    public function emptyValueRepliesSyntax(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MessageBusInterface::class),
            $translator,
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
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Nobody')->willReturn(null);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MessageBusInterface::class),
            $translator,
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Nobody']), $channel, 'Nobody');

        self::assertSame(['error.nick_not_registered'], $messages);
    }

    #[Test]
    public function suspendedNickRepliesSuspended(): void
    {
        $newAccount = $this->createStub(RegisteredNick::class);
        $newAccount->method('getStatus')->willReturn(NickStatus::Suspended);
        $newAccount->method('getId')->willReturn(20);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Suspended')->willReturn($newAccount);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MessageBusInterface::class),
            $translator,
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Suspended']), $channel, 'Suspended');

        self::assertSame(['set.founder.suspended'], $messages);
    }

    #[Test]
    public function notRegisteredStatusRepliesMustBeRegistered(): void
    {
        $newAccount = $this->createStub(RegisteredNick::class);
        $newAccount->method('getStatus')->willReturn(NickStatus::Pending);
        $newAccount->method('getId')->willReturn(20);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Pending')->willReturn($newAccount);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MessageBusInterface::class),
            $translator,
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Pending']), $channel, 'Pending');

        self::assertSame(['set.founder.must_be_registered'], $messages);
    }

    #[Test]
    public function sameAsFounderRepliesCannotBeSelf(): void
    {
        $newAccount = $this->createStub(RegisteredNick::class);
        $newAccount->method('getStatus')->willReturn(NickStatus::Registered);
        $newAccount->method('getId')->willReturn(10);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Founder')->willReturn($newAccount);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MessageBusInterface::class),
            $translator,
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Founder']), $channel, 'Founder');

        self::assertSame(['set.founder.cannot_be_self'], $messages);
    }

    #[Test]
    public function newFounderIsSuccessorRepliesCannotBeSuccessor(): void
    {
        $newAccount = $this->createStub(RegisteredNick::class);
        $newAccount->method('getStatus')->willReturn(NickStatus::Registered);
        $newAccount->method('getId')->willReturn(20);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(20);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Successor')->willReturn($newAccount);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MessageBusInterface::class),
            $translator,
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'Successor']), $channel, 'Successor');

        self::assertSame(['set.founder.cannot_be_successor'], $messages);
    }

    #[Test]
    public function founderLimitExceededRepliesLimitExceeded(): void
    {
        $newAccount = $this->createStub(RegisteredNick::class);
        $newAccount->method('getStatus')->willReturn(NickStatus::Registered);
        $newAccount->method('getId')->willReturn(20);
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
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Busy')->willReturn($newAccount);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MessageBusInterface::class),
            $translator,
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
        $newAccount = $this->createStub(RegisteredNick::class);
        $newAccount->method('getStatus')->willReturn(NickStatus::Registered);
        $newAccount->method('getId')->willReturn(20);
        $channel = $this->createStub(RegisteredChannel::class);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $currentFounder = $this->createStub(RegisteredNick::class);
        $currentFounder->method('getEmail')->willReturn('');
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);
        $nickRepo->method('findByNick')->willReturn($newAccount);
        $nickRepo->method('findById')->willReturn($currentFounder);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            new FounderChangeTokenRegistry(),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MessageBusInterface::class),
            $translator,
        );
        $handler->handle($this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder']), $channel, 'NewFounder');

        self::assertSame(['set.founder.no_email'], $messages);
    }

    #[Test]
    public function invalidTokenRepliesInvalidToken(): void
    {
        $newAccount = $this->createStub(RegisteredNick::class);
        $newAccount->method('getStatus')->willReturn(NickStatus::Registered);
        $newAccount->method('getId')->willReturn(20);
        $currentFounder = $this->createStub(RegisteredNick::class);
        $currentFounder->method('getEmail')->willReturn('founder@example.com');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channelRepo->method('findByFounderNickId')->willReturn([]);
        $accessRepo = $this->createStub(ChannelAccessRepositoryInterface::class);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::atLeastOnce())->method('findByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::atLeastOnce())->method('findById')->with(10)->willReturn($currentFounder);
        $registry = new FounderChangeTokenRegistry();
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MessageBusInterface::class),
            $translator,
        );
        $handler->handle(
            $this->createContext($notifier, $translator, ['#test', 'FOUNDER', 'NewFounder', 'wrong-token']),
            $channel,
            'NewFounder',
        );

        self::assertSame(['set.founder.invalid_token'], $messages);
    }

    #[Test]
    public function validTokenChangesFounderRemovesAccessDispatchesEventAndReplies(): void
    {
        $newAccount = $this->createStub(RegisteredNick::class);
        $newAccount->method('getStatus')->willReturn(NickStatus::Registered);
        $newAccount->method('getId')->willReturn(20);
        $newAccount->method('getNickname')->willReturn('NewFounder');
        $currentFounder = $this->createStub(RegisteredNick::class);
        $currentFounder->method('getEmail')->willReturn('founder@example.com');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->method('getId')->willReturn(1);
        $channel->method('getName')->willReturn('#test');
        $channel->method('getFounderNickId')->willReturn(10);
        $channel->expects(self::once())->method('changeFounder')->with(20);
        $channel->method('getSuccessorNickId')->willReturn(null);
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $existingAccess = $this->createStub(ChannelAccess::class);
        $accessRepo = $this->createMock(ChannelAccessRepositoryInterface::class);
        $accessRepo->expects(self::once())->method('findByChannelAndNick')->with(1, 20)->willReturn($existingAccess);
        $accessRepo->expects(self::once())->method('remove')->with($existingAccess);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::atLeastOnce())->method('findByNick')->with('NewFounder')->willReturn($newAccount);
        $nickRepo->expects(self::atLeastOnce())->method('findById')->willReturnMap([[10, $currentFounder], [20, $newAccount]]);
        $registry = new FounderChangeTokenRegistry();
        $registry->store(1, 20, 'valid-token', (new DateTimeImmutable())->modify('+1 hour'));
        $dispatched = null;
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
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
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetFounderHandler(
            $channelRepo,
            $accessRepo,
            $nickRepo,
            $registry,
            $eventDispatcher,
            $this->createStub(MessageBusInterface::class),
            $translator,
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
}
