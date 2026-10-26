<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Event;

use App\ChanServ\Application\PublishedEvent\ChannelRegistrationDecisionEvent;
use App\MemoServ\Adapter\In\Event\MemoServChannelRegistrationDecisionSubscriber;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecision;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecisionHandlerInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(MemoServChannelRegistrationDecisionSubscriber::class)]
final class MemoServChannelRegistrationDecisionSubscriberTest extends TestCase
{
    #[Test]
    public function mapsPublishedEventToMemoUseCase(): void
    {
        $event = new ChannelRegistrationDecisionEvent(4, '#channel', 12, 7, 'Operator', false, 'bad reason', null, new DateTimeImmutable('2026-10-26T10:00:00+00:00'));
        $handler = $this->createMock(NotifyChannelRegistrationDecisionHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (NotifyChannelRegistrationDecision $input): bool => 4 === $input->channelId
            && '#channel' === $input->channelName
            && 12 === $input->requesterAccountId
            && 7 === $input->operatorAccountId
            && 'Operator' === $input->operatorNickname
            && false === $input->accepted
            && 'bad reason' === $input->rejectionReason
            && null === $input->restrictedUntil
            && $event->occurredAt === $input->occurredAt,
        ));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        new MemoServChannelRegistrationDecisionSubscriber($handler, $logger)->onDecision($event);
    }

    #[Test]
    public function memoFailureIsLoggedButDoesNotEscapeOrExposeReason(): void
    {
        $event = new ChannelRegistrationDecisionEvent(4, '#channel', 12, 7, 'Operator', false, 'private rejection detail', null, new DateTimeImmutable('2026-10-26T10:00:00+00:00'));
        $handler = $this->createMock(NotifyChannelRegistrationDecisionHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willThrowException(new RuntimeException('storage unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Could not store a channel registration decision memo.',
            self::callback(
                static fn (array $context): bool => 4 === $context['channel_id']
                && 12 === $context['requester_account_id']
                && false === $context['accepted']
                && !isset($context['reason'])
                && !isset($context['exception']),
            ),
        );

        new MemoServChannelRegistrationDecisionSubscriber($handler, $logger)->onDecision($event);
    }

    #[Test]
    public function subscribesToPublishedDecisions(): void
    {
        self::assertSame([
            ChannelRegistrationDecisionEvent::class => ['onDecision', 0],
        ], MemoServChannelRegistrationDecisionSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function loggingFailureCannotEscapeAnAlreadyPersistedDecision(): void
    {
        $event = new ChannelRegistrationDecisionEvent(4, '#channel', 12, 7, 'Operator', true, null, null, new DateTimeImmutable('2026-10-26T10:00:00+00:00'));
        $handler = $this->createMock(NotifyChannelRegistrationDecisionHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->willThrowException(new RuntimeException('storage unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->willThrowException(new RuntimeException('logging unavailable'));

        new MemoServChannelRegistrationDecisionSubscriber($handler, $logger)->onDecision($event);
    }
}
