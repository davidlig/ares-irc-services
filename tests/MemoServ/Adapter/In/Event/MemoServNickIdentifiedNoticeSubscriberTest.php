<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Event;

use App\MemoServ\Adapter\In\Event\MemoServNickIdentifiedNoticeSubscriber;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\NickServ\Application\PublishedEvent\NickIdentifiedEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MemoServNickIdentifiedNoticeSubscriber::class)]
final class MemoServNickIdentifiedNoticeSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToNickIdentifiedEvent(): void
    {
        $subscriber = new MemoServNickIdentifiedNoticeSubscriber(
            $this->createStub(MemoRepositoryInterface::class),
            $this->createStub(MemoUserAccountPort::class),
            $this->createStub(MemoServNotifierInterface::class),
            $this->createStub(TranslatorInterface::class),
        );

        self::assertSame(
            [NickIdentifiedEvent::class => ['onNickIdentified', 0]],
            $subscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function doesNothingWhenNoUnreadMemos(): void
    {
        $event = new NickIdentifiedEvent(
            nickId: 10,
            nickname: 'TestUser',
            uid: '001ABC',
        );

        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::once())
            ->method('countUnreadByTargetNick')
            ->with(10)
            ->willReturn(0);

        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::never())->method('getLanguage');

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendNotice');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $subscriber = new MemoServNickIdentifiedNoticeSubscriber(
            $memoRepo,
            $userAccountPort,
            $notifier,
            $translator,
        );

        $subscriber->onNickIdentified($event);
    }

    #[Test]
    public function sendsNoticeWhenHasUnreadMemos(): void
    {
        $event = new NickIdentifiedEvent(
            nickId: 10,
            nickname: 'TestUser',
            uid: '001ABC',
        );

        $memoRepo = $this->createMock(MemoRepositoryInterface::class);
        $memoRepo->expects(self::once())
            ->method('countUnreadByTargetNick')
            ->with(10)
            ->willReturn(3);

        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())
            ->method('getLanguage')
            ->with(10)
            ->willReturn('es');

        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())
            ->method('getNick')
            ->willReturn('MemoServ');
        $notifier->expects(self::once())
            ->method('sendNotice')
            ->with('001ABC', 'Tienes 3 memos pendientes');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('notify.nick_pending', ['%count%' => 3, '%bot%' => 'MemoServ'], 'memoserv', 'es')
            ->willReturn('Tienes 3 memos pendientes');

        $subscriber = new MemoServNickIdentifiedNoticeSubscriber(
            $memoRepo,
            $userAccountPort,
            $notifier,
            $translator,
        );

        $subscriber->onNickIdentified($event);
    }
}
