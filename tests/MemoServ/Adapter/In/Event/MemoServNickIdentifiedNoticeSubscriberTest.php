<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Event;

use App\MemoServ\Adapter\In\Event\MemoServNickIdentifiedNoticeSubscriber;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Application\Port\Out\MemoRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\GetPendingNickNotice\GetPendingNickNoticeHandler;
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
        self::assertSame(
            [NickIdentifiedEvent::class => ['onNickIdentified', 0]],
            MemoServNickIdentifiedNoticeSubscriber::getSubscribedEvents(),
        );
    }

    #[Test]
    public function doesNotPresentWhenTheUseCaseReturnsNoNotice(): void
    {
        $memoRepository = $this->createStub(MemoRepositoryInterface::class);
        $memoRepository->method('countUnreadByTargetNick')->willReturn(0);
        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::never())->method('getLanguage');
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendNotice');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::never())->method('trans');

        $subscriber = new MemoServNickIdentifiedNoticeSubscriber(
            new GetPendingNickNoticeHandler($memoRepository, $userAccountPort),
            $notifier,
            $translator,
        );

        $subscriber->onNickIdentified(new NickIdentifiedEvent(10, 'TestUser', '001ABC'));
    }

    #[Test]
    public function presentsTheSemanticPendingMemoResult(): void
    {
        $memoRepository = $this->createMock(MemoRepositoryInterface::class);
        $memoRepository->expects(self::once())->method('countUnreadByTargetNick')->with(10)->willReturn(3);
        $userAccountPort = $this->createMock(MemoUserAccountPort::class);
        $userAccountPort->expects(self::once())->method('getLanguage')->with(10)->willReturn('es');
        $notifier = $this->createMock(MemoServNotifierInterface::class);
        $notifier->expects(self::once())->method('getNick')->willReturn('MemoServ');
        $notifier->expects(self::once())->method('sendNotice')->with('001ABC', 'Tienes 3 memos pendientes');
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('notify.nick_pending', ['%count%' => 3, '%bot%' => 'MemoServ'], 'memoserv', 'es')
            ->willReturn('Tienes 3 memos pendientes');

        $subscriber = new MemoServNickIdentifiedNoticeSubscriber(
            new GetPendingNickNoticeHandler($memoRepository, $userAccountPort),
            $notifier,
            $translator,
        );

        $subscriber->onNickIdentified(new NickIdentifiedEvent(10, 'TestUser', '001ABC'));
    }
}
