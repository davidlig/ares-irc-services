<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\MemoServ\Subscriber;

use App\Application\MemoServ\Command\MemoServNotifierInterface;
use App\Domain\MemoServ\Repository\MemoRepositoryInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickIdentifiedEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\MemoServ\Subscriber\MemoServNickIdentifiedNoticeSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(MemoServNickIdentifiedNoticeSubscriber::class)]
final class MemoServNickIdentifiedNoticeSubscriberTest extends TestCase
{
    private MemoRepositoryInterface&MockObject $memoRepository;

    private RegisteredNickRepositoryInterface&MockObject $nickRepository;

    private MemoServNotifierInterface&MockObject $notifier;

    private TranslatorInterface&MockObject $translator;

    private MemoServNickIdentifiedNoticeSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->notifier = $this->createMock(MemoServNotifierInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->subscriber = new MemoServNickIdentifiedNoticeSubscriber(
            $this->memoRepository,
            $this->nickRepository,
            $this->notifier,
            $this->translator,
            'en',
        );
    }

    #[Test]
    public function subscribesToNickIdentifiedEvent(): void
    {
        $this->memoRepository->expects(self::never())->method('countUnreadByTargetNick');
        $this->nickRepository->expects(self::never())->method('findById');
        $this->translator->expects(self::never())->method('trans');
        $this->notifier->expects(self::never())->method('sendNotice');
        self::assertSame(
            [NickIdentifiedEvent::class => ['onNickIdentified', 0]],
            MemoServNickIdentifiedNoticeSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function sendsNoticeWhenUserHasUnreadMemos(): void
    {
        $event = new NickIdentifiedEvent(
            nickId: 12345,
            nickname: 'TestUser',
            uid: '001ABCD',
        );

        $this->memoRepository
            ->expects(self::once())
            ->method('countUnreadByTargetNick')
            ->with(12345)
            ->willReturn(3);

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('es');

        $this->nickRepository
            ->expects(self::once())
            ->method('findById')
            ->with(12345)
            ->willReturn($account);

        $this->notifier
            ->method('getNick')
            ->willReturn('MemoServ');
        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('notify.nick_pending', ['%count%' => 3, '%bot%' => 'MemoServ'], 'memoserv', 'es')
            ->willReturn('You have 3 new memos.');

        $this->notifier
            ->expects(self::once())
            ->method('sendNotice')
            ->with('001ABCD', 'You have 3 new memos.');

        $this->subscriber->onNickIdentified($event);
    }

    #[Test]
    public function doesNotSendNoticeWhenNoUnreadMemos(): void
    {
        $event = new NickIdentifiedEvent(
            nickId: 12345,
            nickname: 'TestUser',
            uid: '001ABCD',
        );

        $this->memoRepository
            ->expects(self::once())
            ->method('countUnreadByTargetNick')
            ->with(12345)
            ->willReturn(0);

        $this->nickRepository
            ->expects(self::never())
            ->method('findById');

        $this->notifier
            ->expects(self::never())
            ->method('sendNotice');
        $this->translator->expects(self::never())->method('trans');

        $this->subscriber->onNickIdentified($event);
    }

    #[Test]
    public function usesDefaultLanguageWhenAccountNotFound(): void
    {
        $event = new NickIdentifiedEvent(
            nickId: 12345,
            nickname: 'TestUser',
            uid: '001ABCD',
        );

        $this->memoRepository
            ->expects(self::once())
            ->method('countUnreadByTargetNick')
            ->with(12345)
            ->willReturn(1);

        $this->nickRepository
            ->expects(self::once())
            ->method('findById')
            ->with(12345)
            ->willReturn(null);

        $this->notifier
            ->method('getNick')
            ->willReturn('MemoServ');
        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('notify.nick_pending', ['%count%' => 1, '%bot%' => 'MemoServ'], 'memoserv', 'en')
            ->willReturn('You have 1 new memo.');

        $this->notifier
            ->expects(self::once())
            ->method('sendNotice')
            ->with('001ABCD', 'You have 1 new memo.');

        $this->subscriber->onNickIdentified($event);
    }

    #[Test]
    public function usesAccountLanguageWhenAvailable(): void
    {
        $event = new NickIdentifiedEvent(
            nickId: 12345,
            nickname: 'TestUser',
            uid: '001ABCD',
        );

        $this->memoRepository
            ->expects(self::atLeastOnce())
            ->method('countUnreadByTargetNick')
            ->with(12345)
            ->willReturn(5);

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getLanguage')->willReturn('fr');

        $this->nickRepository
            ->expects(self::atLeastOnce())
            ->method('findById')
            ->with(12345)
            ->willReturn($account);

        $this->notifier
            ->method('getNick')
            ->willReturn('MemoServ');
        $this->translator
            ->expects(self::once())
            ->method('trans')
            ->with('notify.nick_pending', ['%count%' => 5, '%bot%' => 'MemoServ'], 'memoserv', 'fr')
            ->willReturn('You have 5 new memos.');

        $this->notifier
            ->expects(self::once())
            ->method('sendNotice')
            ->with('001ABCD', 'You have 5 new memos.');

        $this->subscriber->onNickIdentified($event);
    }
}
