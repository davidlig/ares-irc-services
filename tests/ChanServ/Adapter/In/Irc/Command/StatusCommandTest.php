<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\StatusCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\UseCase\ShowRegistrationStatus\ShowRegistrationStatusHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(StatusCommand::class)]
final class StatusCommandTest extends TestCase
{
    #[Test]
    public function finiteRestrictionReportsRemainingSecondsToRequester(): void
    {
        $messages = $this->executeStatus($this->rejectedChannel(new DateTimeImmutable('+1 hour')), 10);

        self::assertCount(4, $messages);
        self::assertStringContainsString('status.restriction_until', $messages[3]);
        self::assertStringContainsString('status.restriction_until', $messages[3]);
    }

    #[Test]
    public function indefiniteRestrictionDoesNotReportFiniteWait(): void
    {
        $messages = $this->executeStatus($this->rejectedChannel(null), 10);

        self::assertStringContainsString('status.restriction_indefinite', $messages[3]);
        self::assertStringNotContainsString('remaining_seconds', $messages[3]);
    }

    #[Test]
    public function rejectionDetailsRemainPrivateFromOtherAccounts(): void
    {
        $messages = $this->executeStatus($this->rejectedChannel(new DateTimeImmutable('+1 hour')), 11);

        self::assertCount(1, $messages);
        self::assertStringContainsString('status.rejected_private', $messages[0]);
    }

    #[Test]
    public function authorizedReviewerCanSeeRejectionAndExpiredRestrictionDetails(): void
    {
        $messages = $this->executeStatus($this->rejectedChannel(new DateTimeImmutable('-1 hour')), 11, '#test', true);

        self::assertSame(['status.rejected', 'status.rejection_reason', 'status.rejection_audit', 'status.restriction_expired'], $messages);
    }

    #[Test]
    public function exposesMetadataAndPresentsEveryNonRejectedLifecycleStatus(): void
    {
        $command = new StatusCommand(new ShowRegistrationStatusHandler($this->createStub(RegisteredChannelRepositoryInterface::class)));
        self::assertSame('STATUS', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame('status.syntax', $command->getArgumentDefinition()->syntaxKey);
        self::assertSame('status.help', $command->getHelpKey());
        self::assertSame('status.example', $command->getExampleKey());
        self::assertSame(5, $command->getOrder());
        self::assertSame('status.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertTrue($command->allowsSuspendedChannel());
        self::assertTrue($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());

        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $active = RegisteredChannel::register($at, '#active', 10, 'description');
        $suspended = RegisteredChannel::register($at, '#suspended', 10, 'description');
        $suspended->suspend('policy');
        $forbidden = RegisteredChannel::createForbidden($at, '#forbidden', 'policy');
        $pending = RegisteredChannel::createPendingRegistration($at, '#pending', 10, 'description');
        $deleting = RegisteredChannel::register($at, '#deleting', 10, 'description');
        $deleting->markPendingDeletion($at);

        foreach ([
            [null, '#missing', 'status.unregistered'],
            [$active, '#active', 'status.registered'],
            [$suspended, '#suspended', 'status.suspended'],
            [$forbidden, '#forbidden', 'status.forbidden'],
            [$pending, '#pending', 'status.pending'],
            [$deleting, '#deleting', 'status.pending_deletion'],
        ] as [$channel, $name, $key]) {
            self::assertSame([$key], $this->executeStatus($channel, 10, $name));
        }
    }

    #[Test]
    public function malformedChannelArgumentsAreRejectedBeforeRepositoryLookup(): void
    {
        self::assertSame(['error.syntax'], $this->executeStatus(null, 10, commandArgs: []));
        self::assertSame(['error.syntax'], $this->executeStatus(null, 10, commandArgs: ['not-a-channel']));
    }

    /**
     * @param list<string>|null $commandArgs
     *
     * @return list<string>
     */
    private function executeStatus(?RegisteredChannel $channel, int $requesterAccountId, string $channelName = '#test', bool $operatorAuthorized = false, ?array $commandArgs = null): array
    {
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($channel);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $messages = [];
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translationParameters = [];
        $translator->method('trans')->willReturnCallback(static function (string $key, array $parameters) use (&$translationParameters): string {
            $translationParameters[] = $parameters;

            return $key;
        });
        $context = new ChanServContext(
            new SenderView('UID1', 'Requester', 'ident', 'host', 'cloak', '127.0.0.1'),
            new ChanAccountView($requesterAccountId, 'Requester', 'en'),
            'STATUS',
            $commandArgs ?? [$channelName],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(ServiceNicknameRegistry::class),
        );

        $authorization = $this->createStub(ChanAuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $permission, ChanServContext $context): bool => ChanServPermission::REGISTRATION_REVIEW === $permission && $operatorAuthorized);
        new StatusCommand(new ShowRegistrationStatusHandler($repository), $authorization)->execute($context);

        if (isset($translationParameters[3]) && null !== $channel && $channel->getRegistrationRestrictedUntil() instanceof DateTimeImmutable) {
            self::assertArrayHasKey('%remaining_seconds%', $translationParameters[3]);
            self::assertIsString($translationParameters[3]['%remaining_seconds%']);
            $remainingSeconds = (int) $translationParameters[3]['%remaining_seconds%'];
            self::assertGreaterThanOrEqual(0, $remainingSeconds);
            self::assertLessThanOrEqual(3600, $remainingSeconds);
        }

        return $messages;
    }

    private function rejectedChannel(?DateTimeImmutable $restrictedUntil): RegisteredChannel
    {
        $channel = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-1 day'), '#test', 10, 'description');
        $channel->rejectRegistration('Insufficient information', 2, 'Reviewer', new DateTimeImmutable('-1 hour'), $restrictedUntil);

        return $channel;
    }
}
