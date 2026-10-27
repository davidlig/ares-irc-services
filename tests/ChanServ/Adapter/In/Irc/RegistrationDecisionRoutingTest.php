<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanAuthorizationContextInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\ChanServService;
use App\ChanServ\Adapter\In\Irc\ChanServUserPresentationPreferences;
use App\ChanServ\Adapter\In\Irc\Command\CregCommand;
use App\ChanServ\Adapter\In\Irc\Command\DropCommand;
use App\ChanServ\Adapter\In\Irc\Command\RegisterCommand;
use App\ChanServ\Adapter\In\Irc\Command\StatusCommand;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Adapter\Out\Service\ChanServDebugNotifier;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanAuditSink;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelRegisterThrottlePort;
use App\ChanServ\Application\Port\Out\ChanNetworkActions;
use App\ChanServ\Application\Port\Out\ChanServActivitySink;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegistrationModeQuery;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Application\Service\ChannelSuspensionService;
use App\ChanServ\Application\UseCase\ListPendingRegistrations\ListPendingRegistrationsHandler;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycleHandler;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandler;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandlerInterface;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelResult;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistration;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandlerInterface;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationOutcome;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationResult;
use App\ChanServ\Application\UseCase\ShowRegistrationStatus\ShowRegistrationStatusHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use App\Irc\Adapter\In\Event\CommandExecutedAuditSubscriber;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceDebugNotifierRegistry;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Irc\Application\PublishedEvent\CommandExecutedEvent;
use App\OperServ\Adapter\Out\Irc\ActiveConnectionCommandAuditPresenter;
use App\OperServ\Adapter\Out\Security\ConfiguredRootIdentityRegistry;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\Out\CommandAuditSink;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Security\OperatorAuthorizationService;
use App\OperServ\Application\Security\OperatorPermissionPolicy;
use App\OperServ\Application\Security\RootAuthorizationPolicy;
use App\OperServ\Application\UseCase\RecordCommandAudit;
use App\Shared\Application\Port\EventBusInterface;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use ArrayObject;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_values;
use function dirname;
use function hash;
use function in_array;
use function iterator_to_array;
use function preg_replace;
use function serialize;
use function str_starts_with;

#[CoversClass(ChanServService::class)]
final class RegistrationDecisionRoutingTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function statusPrivacyUsesCentralOperatorPolicyAndDoesNotGrantMutationPermissions(): void
    {
        $requester = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-1 day'), '#private', 10, 'request');
        $requester->rejectRegistration('duplicate', 99, 'Reviewer', new DateTimeImmutable('-1 hour'), new DateTimeImmutable('+1 day'));

        foreach ([
            ['Requester', true, false, 10, false, ['status.header', 'status.rejected', 'status.rejection_reason', 'status.footer']],
            ['Other', true, false, 11, false, ['status.header', 'status.rejected_private', 'status.footer']],
            ['Reviewer', true, true, 11, true, ['status.header', 'status.rejected', 'status.rejection_reason', 'status.rejection_audit', 'status.restriction_until', 'status.footer']],
            ['Root', true, false, 11, true, ['status.header', 'status.rejected', 'status.rejection_reason', 'status.rejection_audit', 'status.restriction_until', 'status.footer']],
            ['UnassignedOper', true, true, 11, false, ['status.header', 'status.rejected_private', 'status.footer']],
            ['Unregistered', false, true, null, false, ['status.header', 'status.rejected_private', 'status.footer']],
        ] as [$nickname, $identified, $ircop, $accountId, $hasRole, $expectedStatus]) {
            $messages = [];
            $review = $this->createMock(ReviewRegistrationHandlerInterface::class);
            $review->expects(self::never())->method('handle');
            $dropService = $this->createMock(ChanDropService::class);
            $dropService->expects(self::never())->method('hardDropChannel');
            $dropService->expects(self::never())->method('softDropChannel');

            $this->service($requester, $nickname, $identified, $ircop, $accountId, $hasRole, $messages, $review, $dropService)->dispatch('STATUS #private', $this->sender($nickname, $identified, $ircop));

            self::assertSame($expectedStatus, $messages);
            if ('Root' !== $nickname) {
                $messages = [];
                $service = $this->service($requester, $nickname, $identified, $ircop, $accountId, $hasRole, $messages, $review, $dropService);
                $service->dispatch('CREG ACCEPT #private', $this->sender($nickname, $identified, $ircop));
                $service->dispatch('DROP #private', $this->sender($nickname, $identified, $ircop));
                self::assertSame(['error.permission_denied', 'error.permission_denied'], $messages);
                self::assertTrue($requester->isRejectedRegistration());
            }
        }
    }

    #[Test]
    public function authorizedDropRemovesFiniteRejectedRegistrationAndSubsequentStatusIsUnregistered(): void
    {
        $channel = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-1 day'), '#private', 10, 'request');
        new ReflectionProperty(RegisteredChannel::class, 'id')->setValue($channel, 41);
        $channel->rejectRegistration('duplicate', 99, 'Reviewer', new DateTimeImmutable('-1 hour'), new DateTimeImmutable('+1 day'));
        $state = new class($channel) {
            public ?RegisteredChannel $current;

            public function __construct(?RegisteredChannel $channel)
            {
                $this->current = $channel;
            }
        };
        $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $channels->method('findByChannelName')->willReturnCallback(static function () use ($state): ?RegisteredChannel {
            return $state->current;
        });
        $channels->method('delete')->willReturnCallback(static function (RegisteredChannel $deletedChannel) use ($state): void {
            self::assertSame('#private', $deletedChannel->getName());
            $state->current = null;
        });
        $boundary = $this->createStub(ChanTransactionBoundary::class);
        $boundary->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $dropService = new ChanDropService(
            $channels,
            $this->createStub(ChanServEventPublisher::class),
            $this->createStub(ChanAuditSink::class),
            $this->createStub(ChanServActivitySink::class),
            $this->createStub(ChanNetworkActions::class),
            $boundary,
        );
        $messages = [];
        $service = $this->service($channels, 'Root', true, false, 11, false, $messages, $this->createStub(ReviewRegistrationHandlerInterface::class), $dropService);

        $service->dispatch('DROP #private', $this->sender('Root', true, false));
        $service->dispatch('STATUS #private', $this->sender('Other', true, false));
        self::assertSame(['drop.success', 'status.header', 'status.unregistered', 'status.footer'], $messages);
        $messages = [];
        $register = $this->createMock(RegisterChannelHandlerInterface::class);
        $register->expects(self::once())->method('handle')->with(self::callback(
            static fn (RegisterChannel $request): bool => '#private' === $request->channelName && 12 === $request->accountId,
        ))->willReturn(RegisterChannelResult::registered());
        $this->service($channels, 'Other', true, false, 12, false, $messages, $this->createStub(ReviewRegistrationHandlerInterface::class), $dropService, $register)
            ->dispatch('REGISTER #private available-again', $this->sender('Other', true, false));

        self::assertNull($state->current);
        self::assertSame(['register.success'], $messages);
    }

    #[Test]
    public function databaseReleaseAndRejectionAuthorizationMatrix(): void
    {
        $repository = new RegisteredChannelDoctrineRepository($this->entityManager);
        $boundary = $this->createStub(ChanTransactionBoundary::class);
        $boundary->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $dropService = new ChanDropService(
            $repository,
            $this->createStub(ChanServEventPublisher::class),
            $this->createStub(ChanAuditSink::class),
            $this->createStub(ChanServActivitySink::class),
            $this->createStub(ChanNetworkActions::class),
            $boundary,
        );

        foreach ([new DateTimeImmutable('+1 day'), new DateTimeImmutable('-1 day'), null] as $restrictedUntil) {
            foreach ([false, true] as $force) {
                foreach ([[], ['chanserv.drop'], ['chanserv.drop', 'chanserv.drop.force']] as $permissions) {
                    $channel = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-2 days'), '#private', 10, 'request');
                    $channel->rejectRegistration('duplicate', 99, 'Reviewer', new DateTimeImmutable('-2 days'), $restrictedUntil);
                    $repository->save($channel);
                    $before = hash('sha256', serialize($this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels')));
                    $messages = new ArrayObject();
                    $service = $this->service(
                        $repository,
                        'RoleUser',
                        true,
                        true,
                        11,
                        true,
                        $messages,
                        $this->createStub(ReviewRegistrationHandlerInterface::class),
                        $dropService,
                        allowedPermissions: $permissions,
                    );

                    $service->dispatch('DROP #private' . ($force ? ' FORCE' : ''), $this->sender('RoleUser', true, true));
                    $this->entityManager->clear();

                    $authorized = in_array('chanserv.drop', $permissions, true)
                        && (!$force || in_array('chanserv.drop.force', $permissions, true));
                    if (!$authorized) {
                        self::assertSame(['error.permission_denied'], iterator_to_array($messages));
                        self::assertSame(
                            $before,
                            hash('sha256', serialize($this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels'))),
                        );
                        $rejectedChannel = $repository->findByChannelName('#private');
                        self::assertNotNull($rejectedChannel);
                        $repository->delete($rejectedChannel);

                        continue;
                    }

                    self::assertNull($this->findChannel($repository));
                    self::assertSame([$force ? 'drop.force_success' : 'drop.success'], iterator_to_array($messages));
                    $messageBuffer = new ArrayObject();
                    $this->service(
                        $repository,
                        'RoleUser',
                        true,
                        true,
                        11,
                        true,
                        $messageBuffer,
                        $this->createStub(ReviewRegistrationHandlerInterface::class),
                        $dropService,
                        allowedPermissions: $permissions,
                    )->dispatch('STATUS #private', $this->sender('RoleUser', true, true));
                    self::assertSame(['status.header', 'status.unregistered', 'status.footer'], iterator_to_array($messageBuffer));

                    $registrationMode = $this->createStub(RegistrationModeQuery::class);
                    $registrationMode->method('current')->willReturn(RegistrationMode::Approval);
                    $activator = new ChannelRegistrationActivator(
                        $this->createStub(ChannelLevelRepositoryInterface::class),
                        $this->createStub(ChannelAkickRepositoryInterface::class),
                    );
                    $register = new RegisterChannelHandler(
                        $repository,
                        $activator,
                        $this->createStub(ChannelRegisterThrottlePort::class),
                        $this->createStub(EventBusInterface::class),
                        $this->createStub(ChanServOperatorAccess::class),
                        $boundary,
                        registrationMode: $registrationMode,
                    );
                    $messages = new ArrayObject();
                    $this->service(
                        $repository,
                        'Other',
                        true,
                        false,
                        12,
                        false,
                        $messages,
                        $this->createStub(ReviewRegistrationHandlerInterface::class),
                        $dropService,
                        $register,
                    )->dispatch('REGISTER #private available-again', $this->sender('Other', true, false));

                    self::assertSame(['register.awaiting_approval'], iterator_to_array($messages));
                    $freshRegistration = $this->findChannel($repository);
                    self::assertNotNull($freshRegistration);
                    self::assertTrue($freshRegistration->isPendingRegistration());
                    self::assertSame(12, $freshRegistration->getFounderNickId());
                    $repository->delete($freshRegistration);
                }
            }
        }
    }

    #[Test]
    public function successfulDecisionDebugPipelineAnnouncesExactlyOnceAndIgnoresNonDecisions(): void
    {
        foreach (['#ircops', ''] as $debugChannel) {
            foreach (['ACCEPT #private', 'REJECT #private duplicated channel', 'PENDING', 'REJECT #private', 'ACCEPT #missing'] as $input) {
                $channel = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-1 day'), '#private', 10, 'request');
                /** @var ArrayObject<int, array{string, string, string}> $notices */
                $notices = new ArrayObject();
                $notifier = $this->createStub(ChanServNotifierInterface::class);
                $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message, string $type) use (&$notices): void {
                    $notices->append([$target, $message, $type]);
                });
                $translator = new Translator('en');
                $translator->addLoader('irc', new IrcYamlTranslationLoader());
                $projectRoot = dirname(__DIR__, 5);
                $translator->addResource('irc', $projectRoot . '/translations/chanserv.en.yaml', 'en', 'chanserv');
                $translator->addResource('irc', $projectRoot . '/translations/common.en.yaml', 'en', 'common');
                $debug = new ChanServDebugNotifier($notifier, $translator, 'en', $debugChannel);
                $presenter = new ActiveConnectionCommandAuditPresenter(new ServiceDebugNotifierRegistry([$debug]));
                /** @var ArrayObject<int, CommandAuditRecord> $records */
                $records = new ArrayObject();
                $sink = $this->createStub(CommandAuditSink::class);
                $sink->method('record')->willReturnCallback(static function (CommandAuditRecord $record) use (&$records, $presenter): void {
                    $records->append($record);
                    $presenter->present($record);
                });
                $subscriber = new CommandExecutedAuditSubscriber(new RecordCommandAudit([$sink]));
                $eventBus = $this->eventBusDispatchingCommandAudit($subscriber);
                $review = $this->createStub(ReviewRegistrationHandlerInterface::class);
                $review->method('handle')->willReturnCallback(static function (ReviewRegistration $request) use ($input): ReviewRegistrationResult {
                    $outcome = 'ACCEPT #missing' === $input
                        ? ReviewRegistrationOutcome::NotPending
                        : (null === $request->rejectionReason ? ReviewRegistrationOutcome::Accepted : ReviewRegistrationOutcome::Rejected);

                    return new ReviewRegistrationResult($outcome);
                });

                foreach ([true, false] as $isRoot) {
                    /** @var ArrayObject<int, array{string, string, string}> $notices */
                    $notices = new ArrayObject();
                    /** @var ArrayObject<int, CommandAuditRecord> $records */
                    $records = new ArrayObject();
                    $messages = new ArrayObject();
                    $service = $this->service(
                        $channel,
                        $isRoot ? 'Root' : 'Other',
                        true,
                        false,
                        11,
                        false,
                        $messages,
                        $review,
                        $this->createStub(ChanDropService::class),
                        eventBus: $eventBus,
                    );
                    $service->dispatch('CREG ' . $input, $this->sender($isRoot ? 'Root' : 'Other', true, false));

                    $isSuccessfulDecision = $isRoot && in_array($input, ['ACCEPT #private', 'REJECT #private duplicated channel'], true);
                    self::assertCount($isSuccessfulDecision ? 1 : 0, $records);
                    $channelNotices = array_values(array_filter(iterator_to_array($notices), static fn (array $notice): bool => $notice[0] === $debugChannel && '' !== $debugChannel));
                    self::assertCount($isSuccessfulDecision && '' !== $debugChannel ? 1 : 0, $channelNotices);

                    if (!$isSuccessfulDecision) {
                        continue;
                    }

                    $expectedLabel = str_starts_with($input, 'ACCEPT') ? 'CREG ACCEPT' : 'CREG REJECT';
                    $record = $records[0] ?? null;
                    self::assertInstanceOf(CommandAuditRecord::class, $record);
                    self::assertSame('CREG', $record->operation);
                    self::assertSame('chanserv', $record->service);
                    self::assertSame(str_starts_with($input, 'ACCEPT') ? 'accepted' : 'rejected', $record->metadata['decision']);
                    if ('' !== $debugChannel) {
                        self::assertSame('#ircops', $channelNotices[0][0]);
                        self::assertSame('NOTICE', $channelNotices[0][2]);
                        $plainMessage = preg_replace('/\x03(?:\d{1,2}(?:,\d{1,2})?)?/', '', $channelNotices[0][1]);
                        self::assertIsString($plainMessage);
                        self::assertStringContainsString($expectedLabel, $plainMessage);
                        self::assertStringContainsString('Root', $plainMessage);
                        self::assertStringContainsString('#private', $plainMessage);
                        if (str_starts_with($input, 'REJECT')) {
                            self::assertStringContainsString('duplicated channel', $plainMessage);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param list<string>|ArrayObject<int, string> $messages
     * @param list<string>                          $allowedPermissions
     */
    private function service(
        RegisteredChannel|RegisteredChannelRepositoryInterface $channel,
        string $nickname,
        bool $identified,
        bool $ircop,
        ?int $accountId,
        bool $hasRole,
        array|ArrayObject &$messages,
        ReviewRegistrationHandlerInterface $review,
        ChanDropService $dropService,
        ?RegisterChannelHandlerInterface $registerHandler = null,
        array $allowedPermissions = [],
        ?EventBusInterface $eventBus = null,
    ): ChanServService {
        if ($channel instanceof RegisteredChannel) {
            $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
            $channels->method('findByChannelName')->willReturn($channel);
        } else {
            $channels = $channel;
        }
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturnCallback(static fn (string $nick): ?ChanAccountView => null === $accountId ? null : new ChanAccountView($accountId, $nick, 'en'));
        $roleAccess = $this->createStub(OperatorRoleAccess::class);
        $roleAccess->method('hasAssignedRole')->willReturn($hasRole);
        $roleAccess->method('hasPermission')->willReturnCallback(static fn (int $id, string $permission): bool => in_array($permission, $allowedPermissions, true));
        $authorization = new OperatorAuthorizationService(
            new RootAuthorizationPolicy(new ConfiguredRootIdentityRegistry('Root')),
            new OperatorPermissionPolicy(new RootAuthorizationPolicy(new ConfiguredRootIdentityRegistry('Root')), $roleAccess),
            $roleAccess,
        );
        $checker = $this->createStub(ChanAuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static function (string $permission, ChanServContext $context) use ($authorization): bool {
            if ('IDENTIFIED' === $permission) {
                return $context->isSenderIdentified();
            }
            $senderNickname = $context->getSenderNickname();

            return null !== $senderNickname && $authorization->permission(new OperatorActor(
                $senderNickname,
                $context->getSenderAccountId(),
                $context->isSenderIdentified(),
                $context->isSenderIrcOperator(),
            ), $permission)->granted;
        });
        $status = new StatusCommand(new ShowRegistrationStatusHandler($channels), $authorization);
        $pending = new ListPendingRegistrationsHandler($channels, $accounts);
        $creg = new CregCommand($pending, $review);
        $lifecycle = new ManageChannelLifecycleHandler(
            $channels,
            $dropService,
            $this->createStub(ChannelForbiddenService::class),
            $this->createStub(ChannelSuspensionService::class),
            $this->createStub(EventBusInterface::class),
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
        );
        $drop = new DropCommand($lifecycle, $checker);
        $commands = [$status, $creg, $drop];
        if (null !== $registerHandler) {
            $commands[] = new RegisterCommand($registerHandler);
        }
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('ChanServ');
        $notifier->method('getServiceKey')->willReturn('chanserv');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            if ($messages instanceof ArrayObject) {
                $messages->append($message);

                return;
            }
            $messages[] = $message;
        });
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);
        $preferences = $this->createStub(ChanServUserPresentationPreferences::class);
        $preferences->method('languageFor')->willReturn('en');
        $preferences->method('prefersPrivateMessages')->willReturn(false);
        $authorizationContext = $this->createStub(ChanAuthorizationContextInterface::class);

        return new ChanServService(
            new ChanServCommandRegistry($commands),
            $channels,
            $accounts,
            $preferences,
            $notifier,
            $translator,
            $this->channelLookup(),
            $this->modeSupportProvider(),
            $this->createStub(NetworkUserLookupPort::class),
            new ServiceNicknameRegistry([]),
            $authorizationContext,
            $checker,
            $eventBus ?? $this->createStub(EventBusInterface::class),
            $this->createStub(CommandAuditRecorder::class),
            'en',
            'UTC',
            $this->createStub(LoggerInterface::class),
        );
    }

    private function sender(string $nickname, bool $identified, bool $ircop): SenderView
    {
        return new SenderView('UID-' . $nickname, $nickname, 'ident', 'host', 'cloak', '127.0.0.1', $identified, $ircop);
    }

    private function eventBusDispatchingCommandAudit(CommandExecutedAuditSubscriber $subscriber): EventBusInterface
    {
        $eventBus = $this->createStub(EventBusInterface::class);
        $eventBus->method('dispatch')->willReturnCallback(static function (object $event) use ($subscriber): void {
            if ($event instanceof CommandExecutedEvent) {
                $subscriber->onPublishedCommandExecuted($event);
            }
        });

        return $eventBus;
    }

    private function findChannel(RegisteredChannelDoctrineRepository $repository): ?RegisteredChannel
    {
        return $repository->findByChannelName('#private');
    }

    private function modeSupportProvider(): ActiveChannelModeSupportProviderInterface
    {
        $provider = $this->createStub(ActiveChannelModeSupportProviderInterface::class);
        $provider->method('getSupport')->willReturn(new NullChannelModeSupport());

        return $provider;
    }

    private function channelLookup(): ChannelLookupPort
    {
        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturnCallback(static fn (string $channelName): ChannelView => new ChannelView(
            $channelName,
            '+o',
            null,
            1,
            [['uid' => 'UID-Other', 'roleLetter' => 'o', 'prefixLetters' => ['o']]],
        ));

        return $lookup;
    }
}
