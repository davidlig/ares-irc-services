<?php

declare(strict_types=1);

namespace App\Tests\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandRegistry;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\ChanServ\Command\Handler\SetSuccessorHandler;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\SenderView;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\NickServ\ValueObject\NickStatus;
use App\Infrastructure\IRC\Protocol\NullChannelModeSupport;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(SetSuccessorHandler::class)]
final class SetSuccessorHandlerTest extends TestCase
{
    private function createContext(
        ChanServNotifierInterface $notifier,
        TranslatorInterface $translator,
        string $senderNick = 'Founder',
    ): ChanServContext {
        return new ChanServContext(
            new SenderView('UID1', $senderNick, 'i', 'h', 'c', 'ip'),
            null,
            'SET',
            ['#test', 'SUCCESSOR', 'NewSuccessor'],
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
    public function emptyValueClearsSuccessorAndRepliesCleared(): void
    {
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('assignSuccessor')->with(null);
        $channel->method('getName')->willReturn('#test');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
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
        $nickRepo = $this->createStub(RegisteredNickRepositoryInterface::class);

        $handler = new SetSuccessorHandler($channelRepo, $nickRepo);
        $handler->handle($this->createContext($notifier, $translator), $channel, '   ');

        self::assertSame(['set.successor.cleared'], $messages);
        self::assertCount(1, $channelNotices);
    }

    #[Test]
    public function nickNotRegisteredRepliesError(): void
    {
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Nobody')->willReturn(null);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetSuccessorHandler($channelRepo, $nickRepo);
        $ctx = $this->createContext($notifier, $translator);
        $handler->handle($ctx, $channel, 'Nobody');

        self::assertSame(['error.nick_not_registered'], $messages);
    }

    #[Test]
    public function suspendedNickRepliesSuspended(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Suspended);
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Suspended')->willReturn($account);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetSuccessorHandler($channelRepo, $nickRepo);
        $handler->handle($this->createContext($notifier, $translator), $channel, 'Suspended');

        self::assertSame(['set.successor.suspended'], $messages);
    }

    #[Test]
    public function notRegisteredStatusRepliesMustBeRegistered(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Pending);
        $channel = $this->createStub(RegisteredChannel::class);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Pending')->willReturn($account);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetSuccessorHandler($channelRepo, $nickRepo);
        $handler->handle($this->createContext($notifier, $translator), $channel, 'Pending');

        self::assertSame(['set.successor.must_be_registered'], $messages);
    }

    #[Test]
    public function founderCannotBeSuccessorRepliesError(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('getId')->willReturn(10);
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('isFounder')->with(10)->willReturn(true);
        $channelRepo = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('FounderNick')->willReturn($account);
        $messages = [];
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $t, string $m) use (&$messages): void {
            $messages[] = $m;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        $handler = new SetSuccessorHandler($channelRepo, $nickRepo);
        $handler->handle($this->createContext($notifier, $translator), $channel, 'FounderNick');

        self::assertSame(['set.successor.cannot_be_founder'], $messages);
    }

    #[Test]
    public function validNickAssignsSuccessorSavesAndSendsNotice(): void
    {
        $account = $this->createStub(RegisteredNick::class);
        $account->method('getStatus')->willReturn(NickStatus::Registered);
        $account->method('getId')->willReturn(20);
        $account->method('getNickname')->willReturn('Successor');
        $channel = $this->createMock(RegisteredChannel::class);
        $channel->expects(self::once())->method('isFounder')->with(20)->willReturn(false);
        $channel->expects(self::once())->method('assignSuccessor')->with(20);
        $channel->method('getName')->willReturn('#test');
        $channelRepo = $this->createMock(RegisteredChannelRepositoryInterface::class);
        $channelRepo->expects(self::once())->method('save')->with($channel);
        $nickRepo = $this->createMock(RegisteredNickRepositoryInterface::class);
        $nickRepo->expects(self::once())->method('findByNick')->with('Successor')->willReturn($account);
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

        $handler = new SetSuccessorHandler($channelRepo, $nickRepo);
        $handler->handle($this->createContext($notifier, $translator), $channel, ' Successor ');

        self::assertSame(['set.successor.updated'], $messages);
        self::assertCount(1, $channelNotices);
    }
}
