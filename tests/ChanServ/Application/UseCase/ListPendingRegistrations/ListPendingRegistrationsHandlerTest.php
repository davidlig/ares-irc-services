<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Application\UseCase\ListPendingRegistrations;

use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\ListPendingRegistrations\ListPendingRegistrationsHandler;
use App\ChanServ\Application\UseCase\ListPendingRegistrations\PendingRegistrationView;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListPendingRegistrationsHandler::class)]
#[CoversClass(PendingRegistrationView::class)]
final class ListPendingRegistrationsHandlerTest extends TestCase
{
    #[Test]
    public function findsOnlyPendingRequestAndEnrichesRequesterNickname(): void
    {
        $submittedAt = new DateTimeImmutable();
        $pending = RegisteredChannel::createPendingRegistration($submittedAt, '#pending', 7, 'description');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($pending);
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::once())->method('findNicknameById')->with(7)->willReturn('Requester');

        $found = new ListPendingRegistrationsHandler($channels, $accounts)->find('#pending');

        self::assertNotNull($found);
        self::assertSame('Requester', $found->requesterNickname);
        self::assertSame('#pending', $found->channelName);
        self::assertSame(7, $found->requesterAccountId);
        self::assertSame('description', $found->description);
        self::assertSame($submittedAt->format(DATE_ATOM), $found->submittedAt->format(DATE_ATOM));
    }

    #[Test]
    public function findRejectsMissingAndNonPendingRows(): void
    {
        $active = RegisteredChannel::register(new DateTimeImmutable(), '#active', 7, 'description');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturnCallback(static fn (string $name): ?RegisteredChannel => '#active' === $name ? $active : null);
        $accounts = $this->createMock(ChanUserAccountPort::class);
        $accounts->expects(self::never())->method('findNicknameById');
        $handler = new ListPendingRegistrationsHandler($channels, $accounts);

        self::assertNull($handler->find('#missing'));
        self::assertNull($handler->find('#active'));
    }

    #[Test]
    public function pagesOnlyPendingRequestsInStableIterationOrder(): void
    {
        $active = RegisteredChannel::register(new DateTimeImmutable(), '#active', 1, 'active');
        $first = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#first', 2, 'first description');
        $rejected = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#rejected', 3, 'rejected');
        $rejected->rejectRegistration('No', 9, 'Op', new DateTimeImmutable(), null);
        $second = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#second', 4, 'second description');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('iterateAll')->willReturn([$active, $first, $rejected, $second]);
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturnCallback(static fn (int $id): ?string => match ($id) {
            2 => 'First',
            default => null,
        });
        $handler = new ListPendingRegistrationsHandler($channels, $accounts);

        self::assertSame(['#first', '#second'], array_map(static fn ($view): string => $view->channelName, $handler->page(1, 2)));
        self::assertSame(['#second'], array_map(static fn ($view): string => $view->channelName, $handler->page(2, 1)));
        self::assertSame([], $handler->page(3, 1));
    }
}
