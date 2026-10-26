<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\CregCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ListPendingRegistrations\ListPendingRegistrationsHandler;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistration;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandlerInterface;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationOutcome;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationResult;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use ArrayObject;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(CregCommand::class)]
final class CregCommandTest extends TestCase
{
    #[Test]
    public function exposesCompleteReviewCommandMetadataAndPermission(): void
    {
        $pending = new ListPendingRegistrationsHandler(
            $this->createStub(RegisteredChannelRepositoryInterface::class),
            $this->createStub(ChanUserAccountPort::class),
        );
        $command = new CregCommand($pending, $this->createStub(ReviewRegistrationHandlerInterface::class));

        self::assertSame('CREG', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame('creg.syntax', $command->getArgumentDefinition()->syntaxKey);
        self::assertSame('creg.help', $command->getHelpKey());
        self::assertSame('creg.example', $command->getExampleKey());
        self::assertSame(4, $command->getOrder());
        self::assertSame('creg.short', $command->getShortDescKey());
        self::assertSame(['PENDING', 'ACCEPT', 'REJECT'], array_column($command->getSubCommandHelp(), 'name'));
        self::assertFalse($command->isOperOnly());
        self::assertSame(ChanServPermission::REGISTRATION_REVIEW, $command->getRequiredPermission());
        self::assertTrue($command->allowsSuspendedChannel());
        self::assertTrue($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());
    }

    #[Test]
    public function pendingListsRequesterSubmissionAndDescription(): void
    {
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('2026-10-26T12:00:00+00:00'), '#test', 7, 'Description');
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('iterateAll')->willReturn([$pending]);
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturn('Requester');
        $review = $this->createMock(ReviewRegistrationHandlerInterface::class);
        $review->expects(self::never())->method('handle');
        [$command, $context, $messages] = $this->context(['PENDING'], $channels, $accounts, $review);

        $command->execute($context);

        self::assertSame(['creg.pending_header', 'creg.pending_item'], iterator_to_array($messages));
    }

    #[Test]
    public function acceptRevalidatesCurrentRequesterRankBeforeCallingReview(): void
    {
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#test', 7, 'Description');
        $channels = $this->channelRepository($pending);
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturn('Requester');
        $review = $this->createMock(ReviewRegistrationHandlerInterface::class);
        $review->expects(self::once())->method('handle')->with(self::callback(static fn (ReviewRegistration $request): bool => $request->requesterHasRequiredChannelRank && $request->channelExistsOnNetwork))->willReturn(new ReviewRegistrationResult(ReviewRegistrationOutcome::Accepted));
        [$command, $context, $messages] = $this->context(['ACCEPT', '#test'], $channels, $accounts, $review, true);

        $outcome = $command->execute($context);

        self::assertSame(['creg.accepted'], iterator_to_array($messages));
        self::assertTrue($outcome->success);
    }

    #[Test]
    public function acceptCannotBorrowApproverRankWhenRequesterHasOnlyVoice(): void
    {
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#test', 7, 'Description');
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturn('Requester');
        $review = $this->createMock(ReviewRegistrationHandlerInterface::class);
        $review->expects(self::once())->method('handle')->with(self::callback(static fn (ReviewRegistration $request): bool => !$request->requesterHasRequiredChannelRank))->willReturn(new ReviewRegistrationResult(ReviewRegistrationOutcome::RequesterIneligible));
        [$command, $context, $messages] = $this->context(['ACCEPT', '#test'], $this->channelRepository($pending), $accounts, $review, true, prefixLetters: ['v']);

        self::assertFalse($command->execute($context)->success);
        self::assertSame(['creg.requester_ineligible'], iterator_to_array($messages));
    }

    #[Test]
    public function rejectRequiresNonblankReasonAndHasNoReviewSideEffectsOtherwise(): void
    {
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#test', 7, 'Description');
        $channels = $this->channelRepository($pending);
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $review = $this->createMock(ReviewRegistrationHandlerInterface::class);
        $review->expects(self::never())->method('handle');
        [$command, $context, $messages] = $this->context(['REJECT', '#test', '   '], $channels, $accounts, $review);

        self::assertFalse($command->execute($context)->success);
        self::assertSame(['error.syntax'], iterator_to_array($messages));
    }

    #[Test]
    public function missingApproverIdentityCannotReachReviewHandler(): void
    {
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#test', 7, 'Description');
        $review = $this->createMock(ReviewRegistrationHandlerInterface::class);
        $review->expects(self::never())->method('handle');
        [$command, $context, $messages] = $this->context(['REJECT', '#test', 'Reason'], $this->channelRepository($pending), $this->createStub(ChanUserAccountPort::class), $review, withOperatorAccount: false);

        self::assertFalse($command->execute($context)->success);
        self::assertSame(['error.invalid_channel'], iterator_to_array($messages));
    }

    #[Test]
    public function rejectPassesRequiredReasonAndReportsSuccess(): void
    {
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#test', 7, 'Description');
        $channels = $this->channelRepository($pending);
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $review = $this->createMock(ReviewRegistrationHandlerInterface::class);
        $review->expects(self::once())->method('handle')->with(self::callback(static fn (ReviewRegistration $request): bool => 'Needs more detail' === $request->rejectionReason))->willReturn(new ReviewRegistrationResult(ReviewRegistrationOutcome::Rejected));
        [$command, $context, $messages] = $this->context(['REJECT', '#test', 'Needs', 'more', 'detail'], $channels, $accounts, $review);

        $outcome = $command->execute($context);

        self::assertSame(['creg.rejected'], iterator_to_array($messages));
        self::assertTrue($outcome->success);
    }

    #[Test]
    public function acceptanceFailuresArePresentedWithoutSuccess(): void
    {
        foreach ([
            [ReviewRegistrationOutcome::NotPending, 'creg.not_pending'],
            [ReviewRegistrationOutcome::ChannelNotOnNetwork, 'creg.channel_not_on_network'],
            [ReviewRegistrationOutcome::FounderLimitExceeded, 'creg.founder_limit'],
            [ReviewRegistrationOutcome::RequesterInactive, 'creg.requester_inactive'],
            [ReviewRegistrationOutcome::RequesterIneligible, 'creg.requester_ineligible'],
        ] as [$failure, $message]) {
            $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#test', 7, 'Description');
            $channels = $this->channelRepository($pending);
            $review = $this->createMock(ReviewRegistrationHandlerInterface::class);
            $review->expects(self::once())->method('handle')->willReturn(new ReviewRegistrationResult($failure));
            [$command, $context, $messages] = $this->context(['ACCEPT', '#test'], $channels, $this->createStub(ChanUserAccountPort::class), $review);

            self::assertFalse($command->execute($context)->success);
            self::assertSame([$message], iterator_to_array($messages));
        }
    }

    #[Test]
    public function missingRequesterMembershipDoesNotBorrowOperatorRank(): void
    {
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#test', 7, 'Description');
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturn('Requester');
        $review = $this->createMock(ReviewRegistrationHandlerInterface::class);
        $review->expects(self::once())->method('handle')->with(self::callback(static fn (ReviewRegistration $request): bool => !$request->requesterHasRequiredChannelRank))->willReturn(new ReviewRegistrationResult(ReviewRegistrationOutcome::RequesterIneligible));
        [$command, $context, $messages] = $this->context(
            ['ACCEPT', '#test'],
            $this->channelRepository($pending),
            $accounts,
            $review,
            requesterOnline: true,
            memberUid: 'OTHER-UID',
        );

        self::assertFalse($command->execute($context)->success);
        self::assertSame(['creg.requester_ineligible'], iterator_to_array($messages));
    }

    private function channelRepository(RegisteredChannel $pending): RegisteredChannelRepositoryInterface
    {
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturn($pending);

        return $channels;
    }

    /** @param list<string> $args
     * @return array{CregCommand, ChanServContext, ArrayObject<int, string>}
     */
    /**
     * @param list<string> $args
     * @param list<string> $prefixLetters
     *
     * @return array{CregCommand, ChanServContext, ArrayObject<int, string>}
     */
    private function context(
        array $args,
        RegisteredChannelRepositoryInterface $channels,
        ChanUserAccountPort $accounts,
        ReviewRegistrationHandlerInterface $review,
        bool $requesterOnline = false,
        bool $requesterIdentified = true,
        array $prefixLetters = ['o'],
        bool $withOperatorAccount = true,
        string $memberUid = 'UID2',
    ): array {
        /** @var ArrayObject<int, string> $messages */
        $messages = new ArrayObject();
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $uid, string $message) use (&$messages): void { $messages[] = $message; });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);
        $channelLookup = $this->createStub(ChannelLookupPort::class);
        $channelLookup->method('findByChannelName')->willReturn($requesterOnline ? new ChannelView('#test', '+nt', null, 1, [['uid' => $memberUid, 'roleLetter' => 'o', 'prefixLetters' => $prefixLetters]]) : null);
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($requesterOnline ? new SenderView('UID2', 'Requester', 'i', 'h', 'c', 'ip', $requesterIdentified) : null);
        $context = new ChanServContext(
            new SenderView('UID1', 'Operator', 'i', 'h', 'c', 'ip'),
            $withOperatorAccount ? new ChanAccountView(9, 'Operator', 'en') : null,
            'CREG',
            $args,
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $channelLookup,
            new NullChannelModeSupport(),
            $userLookup,
            $this->createStub(ServiceNicknameRegistry::class),
        );

        return [new CregCommand(new ListPendingRegistrationsHandler($channels, $accounts), $review), $context, $messages];
    }
}
