<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\ChanServ\Adapter\In\Event\ChanServAccessChannelDropSubscriber;
use App\ChanServ\Adapter\In\Event\ChanServAkickChannelDropSubscriber;
use App\ChanServ\Adapter\In\Event\ChanServHistoryChannelDropSubscriber;
use App\ChanServ\Adapter\In\Event\ChanServLevelsChannelDropSubscriber;
use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanAuthorizationContextInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\ChanServService;
use App\ChanServ\Adapter\In\Irc\ChanServUserPresentationPreferences;
use App\ChanServ\Adapter\In\Irc\Command\CregCommand;
use App\ChanServ\Adapter\In\Irc\Command\DropCommand;
use App\ChanServ\Adapter\In\Irc\Command\ListCommand;
use App\ChanServ\Adapter\In\Irc\Command\RegisterCommand;
use App\ChanServ\Adapter\In\Irc\Command\StatusCommand;
use App\ChanServ\Adapter\Out\NickServ\NickServIdentifiedRequesterSession;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAccessDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAkickDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelHistoryDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelLevelDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Adapter\Out\Security\OperChanServOperatorAccess;
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
use App\ChanServ\Application\PublishedEvent\ChannelRegistrationDecisionEvent;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Application\Service\ChannelForbiddenService;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Application\Service\ChannelSuspensionService;
use App\ChanServ\Application\Service\NickChannelAssociationQueryService;
use App\ChanServ\Application\UseCase\CleanupChannelAccess\CleanupChannelAccessHandler;
use App\ChanServ\Application\UseCase\CleanupChannelAkick\CleanupChannelAkickHandler;
use App\ChanServ\Application\UseCase\CleanupChannelHistory\CleanupChannelHistoryHandler;
use App\ChanServ\Application\UseCase\CleanupChannelLevels\CleanupChannelLevelsHandler;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannelsHandler;
use App\ChanServ\Application\UseCase\List\ListRegisteredChannelsHandlerInterface;
use App\ChanServ\Application\UseCase\ListPendingRegistrations\ListPendingRegistrationsHandler;
use App\ChanServ\Application\UseCase\ManageLifecycle\ManageChannelLifecycleHandler;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannel;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandler;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelHandlerInterface;
use App\ChanServ\Application\UseCase\RegisterChannel\RegisterChannelResult;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistration;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandler;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandlerInterface;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationOutcome;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationResult;
use App\ChanServ\Application\UseCase\ShowRegistrationStatus\ShowRegistrationStatusHandler;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelHistory;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\Exception\ChannelAlreadyRegisteredException;
use App\ChanServ\Domain\ValueObject\RegistrationMode;
use App\Irc\Adapter\In\Event\CommandExecutedAuditSubscriber;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ActiveChannelModeSupportProviderInterface;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceDebugNotifierRegistry;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\Irc\Application\PublishedEvent\CommandExecutedEvent;
use App\MemoServ\Adapter\In\Event\MemoServChannelRegistrationDecisionSubscriber;
use App\MemoServ\Adapter\Out\Persistence\Doctrine\MemoDoctrineRepository;
use App\MemoServ\Adapter\Out\Translation\ChannelRegistrationMemoTextAdapter;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\Port\Out\ServiceMemoSender;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecisionHandler;
use App\NickServ\Adapter\In\Irc\Command\InfoCommand;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\In\Irc\NickServService;
use App\NickServ\Adapter\Out\ChanServ\ChanServNickAssociatedChannelsAdapter;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;
use App\NickServ\Application\Port\Out\AuthorizationCheckerInterface;
use App\NickServ\Application\Port\Out\AuthorizationContextInterface;
use App\NickServ\Application\Service\VhostDisplayResolver;
use App\NickServ\Application\UseCase\Info\InfoNickHandler;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\OperServ\Adapter\Out\Irc\ActiveConnectionCommandAuditPresenter;
use App\OperServ\Adapter\Out\Irc\DebugChannelCommandAuditSink;
use App\OperServ\Adapter\Out\Logging\PsrCommandAuditSink;
use App\OperServ\Adapter\Out\Security\ConfiguredRootIdentityRegistry;
use App\OperServ\Application\IrcopAccessQueryService;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\Out\CommandAuditSink;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Security\OperatorAuthorizationService;
use App\OperServ\Application\Security\OperatorPermissionPolicy;
use App\OperServ\Application\Security\RootAuthorizationPolicy;
use App\OperServ\Application\UseCase\RecordCommandAudit;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Application\Port\EventBusInterface;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use ArrayObject;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_filter;
use function array_key_last;
use function array_map;
use function array_values;
use function ceil;
use function dirname;
use function explode;
use function hash;
use function implode;
use function in_array;
use function iterator_to_array;
use function preg_replace;
use function serialize;
use function str_starts_with;

#[CoversClass(ChanServService::class)]
#[CoversClass(RegisterCommand::class)]
#[CoversClass(CommandExecutedAuditSubscriber::class)]
#[CoversClass(ChanServDebugNotifier::class)]
#[CoversClass(ManageChannelLifecycleHandler::class)]
#[CoversClass(ReviewRegistrationHandler::class)]
#[CoversClass(NickServIdentifiedRequesterSession::class)]
#[CoversClass(IdentifiedSessionRegistry::class)]
#[CoversClass(NickChannelAssociationQueryService::class)]
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
                self::assertSame(["\x0304✗\x03\x0F presentation.error.permission_denied", "\x0304✗\x03\x0F presentation.error.permission_denied"], $messages);
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
        self::assertSame(["\x0303✔\x03\x0F drop.force_success", 'status.header', 'status.unregistered', 'status.footer'], $messages);
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/chanserv.en.yaml', 'en', 'chanserv');
        $rendered = $translator->trans('drop.force_success', ['%channel%' => '#private'], 'chanserv');
        self::assertStringContainsString('permanently', $rendered);
        self::assertStringNotContainsString('grace', $rendered);
        self::assertStringNotContainsString('restore', $rendered);
        $messages = [];
        $register = $this->createMock(RegisterChannelHandlerInterface::class);
        $register->expects(self::once())->method('handle')->with(self::callback(
            static fn (RegisterChannel $request): bool => '#private' === $request->channelName && 12 === $request->accountId,
        ))->willReturn(RegisterChannelResult::registered());
        $this->service($channels, 'Other', true, false, 12, false, $messages, $this->createStub(ReviewRegistrationHandlerInterface::class), $dropService, $register)
            ->dispatch('REGISTER #private available-again', $this->sender('Other', true, false));

        self::assertNull($state->current);
        self::assertSame(["\x0303✔\x03\x0F register.success"], $messages);
    }

    /** @return list<array{bool, list<string>, string, bool}> */
    public static function pendingCancellationCases(): array
    {
        return [
            [false, ['chanserv.drop'], "\x0303✔\x03\x0F drop.force_success", true],
            [false, [], "\x0304✗\x03\x0F presentation.error.permission_denied", false],
            [false, ['chanserv.drop.force'], "\x0304✗\x03\x0F presentation.error.permission_denied", false],
            [true, [], "\x0304✗\x03\x0F presentation.error.permission_denied", false],
            [true, ['chanserv.drop'], "\x0304✗\x03\x0F presentation.error.permission_denied", false],
            [true, ['chanserv.drop.force'], "\x0304✗\x03\x0F presentation.error.permission_denied", false],
            [true, ['chanserv.drop', 'chanserv.drop.force'], "\x0303✔\x03\x0F drop.force_success", true],
        ];
    }

    /** @param list<string> $permissions */
    #[Test]
    #[DataProvider('pendingCancellationCases')]
    public function pendingCancellationPreservesPermissionsAndOnlyDeletesItsTarget(bool $force, array $permissions, string $expected, bool $deleted): void
    {
        $repository = new RegisteredChannelDoctrineRepository($this->entityManager);
        $other = RegisteredChannel::register(new DateTimeImmutable('-1 day'), '#untouched', 10, 'approved');
        $repository->save($other);
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-1 day'), '#private', 10, 'request');
        $repository->save($pending);
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels');
        $boundary = $this->createStub(ChanTransactionBoundary::class);
        $boundary->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $publisher = $this->createMock(ChanServEventPublisher::class);
        $publisher->expects($deleted ? self::exactly(2) : self::never())->method('publish');
        $network = $this->createMock(ChanNetworkActions::class);
        $network->expects(self::never())->method('removeRegistrationForPendingDeletion');
        $drop = new ChanDropService($repository, $publisher, $this->createStub(ChanAuditSink::class), $this->createStub(ChanServActivitySink::class), $network, $boundary);
        $observed = new class {
            public ?CommandOutcome $outcome = null;
        };
        $commandEvents = $this->createStub(EventBusInterface::class);
        $commandEvents->method('dispatch')->willReturnCallback(static function (object $event) use ($observed): void {
            if ($event instanceof CommandExecutedEvent) {
                $observed->outcome = $event->outcome;
            }
        });
        $messages = new ArrayObject();
        $this->service(
            $repository,
            'RoleUser',
            true,
            true,
            11,
            true,
            $messages,
            $this->createStub(ReviewRegistrationHandlerInterface::class),
            $drop,
            allowedPermissions: $permissions,
            eventBus: $commandEvents,
        )->dispatch('DROP #private' . ($force ? ' FORCE' : ''), $this->sender('RoleUser', true, true));
        self::assertSame([$expected], iterator_to_array($messages));
        if (!in_array('chanserv.drop', $permissions, true)) {
            self::assertNull($observed->outcome);
        } else {
            self::assertNotNull($observed->outcome);
            self::assertSame($deleted, $observed->outcome->success);
            if ($deleted) {
                self::assertNotNull($observed->outcome->auditData);
                self::assertSame('#private', $observed->outcome->auditData->target);
                self::assertSame($force ? ['force' => true] : [], $observed->outcome->auditData->extra);
            } else {
                self::assertNull($observed->outcome->auditData);
            }
        }
        $this->entityManager->clear();
        $target = $repository->findByChannelName('#private');
        self::assertNotNull($repository->findByChannelName('#untouched'));
        if ($deleted) {
            self::assertNull($target);
            $mode = $this->createStub(RegistrationModeQuery::class);
            $mode->method('current')->willReturn(RegistrationMode::Approval);
            $register = new RegisterChannelHandler(
                $repository,
                new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class)),
                $this->createStub(ChannelRegisterThrottlePort::class),
                $this->createStub(EventBusInterface::class),
                $this->createStub(ChanServOperatorAccess::class),
                $boundary,
                registrationMode: $mode,
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
                $drop,
                $register,
            )->dispatch('REGISTER #private new-request', $this->sender('Other', true, false));
            self::assertSame(["\x0303✔\x03\x0F register.awaiting_approval"], iterator_to_array($messages));
            $fresh = new RegisteredChannelDoctrineRepository($this->entityManager)->findByChannelName('#private');
            self::assertNotNull($fresh);
            self::assertTrue($fresh->isPendingRegistration());
            self::assertSame(12, $fresh->getFounderNickId());
        } else {
            self::assertNotNull($target);
            self::assertTrue($target->isPendingRegistration());
            self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels'));
            $repository->delete($target);
        }
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
                        self::assertSame(["\x0304✗\x03\x0F presentation.error.permission_denied"], iterator_to_array($messages));
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
                    self::assertSame(["\x0303✔\x03\x0F drop.force_success"], iterator_to_array($messages));
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

                    self::assertSame(["\x0303✔\x03\x0F register.awaiting_approval"], iterator_to_array($messages));
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
                $debug = new ChanServDebugNotifier($notifier, $translator, 'en', $debugChannel, new IrcRenderer(new FixedStyleProvider(PresentationStyle::default())));
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

    /** @return iterable<string, array{RegistrationMode, string, bool, bool, ?string, string}> */
    public static function registrationAudits(): iterable
    {
        foreach ([RegistrationMode::Direct, RegistrationMode::Approval] as $mode) {
            foreach ([['Other', false, false], ['Oper', true, true], ['Root', false, false], ['RawOper', true, false]] as [$nick, $oper, $role]) {
                foreach ([null, '', '#ircops'] as $debug) {
                    foreach (['success', 'syntax', 'unidentified', 'restricted', 'pending-deletion', 'already-registered', ...('Other' === $nick ? ['quota', 'rank', 'throttle', 'missing-network'] : [])] as $case) {
                        yield $mode->name . '/' . $nick . '/' . ($debug ?? 'null') . '/' . $case => [$mode, $nick, $oper, $role, $debug, $case];
                    }
                }
            }
        }
    }

    #[Test]
    #[DataProvider('registrationAudits')]
    public function registerUsesCentralAuditByActualOutcome(RegistrationMode $mode, string $nick, bool $oper, bool $role, ?string $debugChannel, string $case): void
    {
        $repository = new RegisteredChannelDoctrineRepository($this->entityManager);
        if (in_array($case, ['restricted', 'pending-deletion', 'already-registered'], true)) {
            $existing = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-1 day'), '#private', 10, 'previous');
            if ('restricted' === $case) {
                $existing->rejectRegistration('duplicate', 99, 'Reviewer', new DateTimeImmutable(), null);
            } elseif ('pending-deletion' === $case) {
                $existing->approveRegistration(new DateTimeImmutable('-1 hour'));
                $existing->markPendingDeletion(new DateTimeImmutable());
            }
            $repository->save($existing);
        }
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels');
        /** @var ArrayObject<int, array{string, string, string}> $notices */
        $notices = new ArrayObject();
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message, string $type) use ($notices): void {
            $notices->append([$target, $message, $type]);
        });
        $translator = new Translator('en');
        $translator->addLoader('irc', new IrcYamlTranslationLoader());
        foreach (['chanserv', 'common'] as $domain) {
            $translator->addResource('irc', dirname(__DIR__, 5) . '/translations/' . $domain . '.en.yaml', 'en', $domain);
        }
        $presenter = new ActiveConnectionCommandAuditPresenter(new ServiceDebugNotifierRegistry([
            new ChanServDebugNotifier($notifier, $translator, 'en', $debugChannel, new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()))),
        ]));
        /** @var ArrayObject<int, CommandAuditRecord> $records */
        $records = new ArrayObject();
        $sink = $this->createStub(CommandAuditSink::class);
        $sink->method('record')->willReturnCallback(static function (CommandAuditRecord $record) use ($records): void {
            $records->append($record);
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects('success' === $case ? self::once() : self::never())->method('info')
            ->with('Security command audit', self::callback(static fn (array $context): bool => 'user_action' === $context['category']
                && 'REGISTER' === $context['operation'] && $nick === $context['actor']
                && (RegistrationMode::Approval === $mode ? 'awaiting_approval' : 'registered') === $context['metadata_outcome']));
        $subscriber = new CommandExecutedAuditSubscriber(new RecordCommandAudit([
            $sink, new PsrCommandAuditSink($logger), new DebugChannelCommandAuditSink($presenter),
        ]));
        /** @var ArrayObject<int, CommandExecutedEvent> $executions */
        $executions = new ArrayObject();
        $events = $this->createStub(EventBusInterface::class);
        $events->method('dispatch')->willReturnCallback(static function (object $event) use ($subscriber, $executions): void {
            if ($event instanceof CommandExecutedEvent) {
                $executions->append($event);
                $subscriber->onPublishedCommandExecuted($event);
            }
        });
        $boundary = $this->createStub(ChanTransactionBoundary::class);
        $boundary->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $query = $this->createStub(RegistrationModeQuery::class);
        $query->method('current')->willReturn($mode);
        $roles = $this->createStub(OperatorRoleAccess::class);
        $roles->method('hasAssignedRole')->willReturn($role);
        $root = new RootAuthorizationPolicy(new ConfiguredRootIdentityRegistry('Root'));
        $operators = new OperChanServOperatorAccess(new IrcopAccessQueryService(new OperatorAuthorizationService(
            $root,
            new OperatorPermissionPolicy($root, $roles),
            $roles,
        )));
        $throttle = $this->createStub(ChannelRegisterThrottlePort::class);
        $throttle->method('getRemainingCooldownSeconds')->willReturn('throttle' === $case ? 60 : 0);
        $register = new RegisterChannelHandler($repository, new ChannelRegistrationActivator(
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChannelAkickRepositoryInterface::class),
        ), $throttle, $events, $operators, $boundary, maxChannelsPerNick: 'quota' === $case ? 0 : 3, registrationMode: $query);
        $sender = $this->sender($nick, 'unidentified' !== $case, $oper);
        $messages = new ArrayObject();
        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturn('missing-network' === $case ? null : new ChannelView(
            '#private',
            '+o',
            null,
            1,
            [['uid' => $sender->uid, 'roleLetter' => 'rank' === $case ? 'v' : 'o']],
        ));
        $drop = $this->createStub(ChanDropService::class);
        try {
            $this->service(
                $repository,
                $nick,
                $sender->isIdentified,
                $oper,
                10,
                $role,
                $messages,
                $this->createStub(ReviewRegistrationHandlerInterface::class),
                $drop,
                $register,
                eventBus: $events,
                requester: $sender,
                channelLookupOverride: $lookup,
            )->dispatch('syntax' === $case ? 'REGISTER #private' : 'REGISTER #private confidential-description', $sender);
            self::assertNotSame('already-registered', $case);
        } catch (ChannelAlreadyRegisteredException) {
            self::assertSame('already-registered', $case);
        }
        $success = 'success' === $case;
        self::assertCount($success ? 1 : 0, $records);
        self::assertCount($success && null !== $debugChannel && '' !== $debugChannel ? 1 : 0, $notices);
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM channel_history'));
        if (!$success) {
            foreach ($executions as $execution) {
                self::assertNotNull($execution->outcome);
                self::assertFalse($execution->outcome->success);
                self::assertNull($execution->outcome->auditData);
            }
            self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels'));
            $reply = match ($case) {
                'syntax' => "\x0304✗\x03\x0F presentation.error.syntax",
                'unidentified' => "\x0304✗\x03\x0F presentation.error.not_identified",
                'restricted' => "\x0304✗\x03\x0F register.restricted_indefinite",
                'pending-deletion' => "\x0304✗\x03\x0F drop.pending_deletion",
                'quota' => "\x0304✗\x03\x0F register.limit_exceeded",
                'rank' => "\x0304✗\x03\x0F register.insufficient_channel_rank",
                'throttle' => "\x0307⚠\x03\x0F register.throttled",
                'missing-network' => "\x0304✗\x03\x0F register.channel_not_on_network",
                default => null,
            };
            self::assertSame(null === $reply ? [] : [$reply], iterator_to_array($messages));

            return;
        }
        $pending = RegistrationMode::Approval === $mode;
        self::assertSame([$pending ? "\x0303✔\x03\x0F register.awaiting_approval" : "\x0303✔\x03\x0F register.success"], iterator_to_array($messages));
        self::assertCount(1, $executions);
        $execution = $executions[0];
        self::assertInstanceOf(CommandExecutedEvent::class, $execution);
        self::assertNotNull($execution->outcome);
        self::assertTrue($execution->outcome->success);
        self::assertSame('IDENTIFIED', $execution->permission);
        $this->entityManager->clear();
        $channel = $this->findChannel($repository);
        self::assertNotNull($channel);
        self::assertSame($pending, $channel->isPendingRegistration());
        self::assertSame(10, $channel->getFounderNickId());
        self::assertCount(1, $repository->findByFounderNickId(10));
        $record = $records[0];
        self::assertInstanceOf(CommandAuditRecord::class, $record);
        self::assertSame('user_action', $record->category->value);
        self::assertSame('IDENTIFIED', $record->permission);
        self::assertSame('chanserv', $record->service);
        self::assertSame($nick, $record->actor);
        self::assertSame('REGISTER', $record->operation);
        self::assertSame('#private', $record->target);
        self::assertSame(['outcome' => $pending ? 'awaiting_approval' : 'registered'], $record->metadata);
        self::assertNull($record->reason);
        self::assertNull($record->targetHost);
        self::assertNull($record->targetIp);
        if (null !== $debugChannel && '' !== $debugChannel) {
            $notice = $notices[0];
            self::assertNotNull($notice);
            self::assertSame('#ircops', $notice[0]);
            self::assertSame('NOTICE', $notice[2]);
            $plain = preg_replace('/\x03(?:\d{1,2}(?:,\d{1,2})?)?|\x0F/', '', $notice[1]);
            self::assertSame($nick . ($pending ? ' submitted a registration request for #private' : ' registered channel #private'), $plain);
            self::assertStringNotContainsString('confidential-description', $notice[1]);
        }
    }

    /** @return iterable<string, array{string, bool, bool, int, int, int, string, string, string, string, bool}> */
    public static function requesterQuotas(): iterable
    {
        yield 'rejected rows do not count' => ['Other', false, false, 2, 1, 2, '', "\x0303✔\x03\x0F register.awaiting_approval", 'creg.accepted', '', true];
        yield 'ordinary cap remains with oper reviewer' => ['Other', false, false, 2, 2, 0, '', "\x0304✗\x03\x0F register.limit_exceeded", 'creg.founder_limit', '', true];
        yield 'requester oper exemption with different reviewer' => ['RequesterOper', true, true, 2, 3, 0, '', "\x0303✔\x03\x0F register.awaiting_approval", 'creg.accepted', '', true];
        yield 'root exemption without raw oper flag' => ['Root', false, false, 2, 3, 0, '', "\x0303✔\x03\x0F register.awaiting_approval", 'creg.accepted', '', true];
        yield 'raw oper without assigned role is not exempt' => ['RawOper', true, false, 2, 2, 0, '', "\x0304✗\x03\x0F register.limit_exceeded", 'creg.founder_limit', '', true];
        yield 'non-default larger maximum' => ['Other', false, false, 5, 3, 0, '', "\x0303✔\x03\x0F register.awaiting_approval", 'creg.accepted', '', true];
        yield 'same nickname but different authenticated UID cannot exempt' => ['RequesterOper', true, true, 2, 3, 0, '', "\x0303✔\x03\x0F register.awaiting_approval", 'creg.founder_limit', 'different-session', true];
        yield 'offline requester remains ineligible' => ['RequesterOper', true, true, 2, 3, 0, '', "\x0303✔\x03\x0F register.awaiting_approval", 'creg.requester_ineligible', '', false];
        foreach (['pending', 'suspended', 'pending-deletion'] as $state) {
            yield $state . ' still counts' => ['Other', false, false, 2, 1, 0, $state, "\x0304✗\x03\x0F register.limit_exceeded", 'creg.founder_limit', '', true];
        }
    }

    #[Test]
    #[DataProvider('requesterQuotas')]
    public function requesterQuotaPolicyThroughPublicRouting(string $nickname, bool $ircop, bool $assignedRole, int $maximum, int $approved, int $rejected, string $extraState, string $registerReply, string $acceptReply, string $sessionUid, bool $online): void
    {
        $repository = new RegisteredChannelDoctrineRepository($this->entityManager);
        $at = new DateTimeImmutable('-1 day');
        for ($i = 0; $i < $approved; ++$i) {
            $repository->save(RegisteredChannel::register($at, '#approved' . $i, 10, 'approved'));
        }
        for ($i = 0; $i < $rejected; ++$i) {
            $channel = RegisteredChannel::createPendingRegistration($at, '#rejected' . $i, 10, 'rejected');
            $channel->rejectRegistration('duplicate', 99, 'Reviewer', $at, new DateTimeImmutable('+1 day'));
            $repository->save($channel);
        }
        if ('' !== $extraState) {
            $extra = 'pending' === $extraState
                ? RegisteredChannel::createPendingRegistration($at, '#counted', 10, 'pending')
                : RegisteredChannel::register($at, '#counted', 10, 'registered');
            if ('suspended' === $extraState) {
                $extra->suspend('test');
            } elseif ('pending-deletion' === $extraState) {
                $extra->markPendingDeletion($at);
            }
            $repository->save($extra);
        }
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturnCallback(static fn (string $nick): ChanAccountView => new ChanAccountView('Reviewer' === $nick ? 99 : 10, $nick, 'en'));
        $accounts->method('findAccountById')->willReturn(new ChanAccountView(10, $nickname, 'en'));
        $accounts->method('findNicknameById')->willReturn($nickname);
        $roles = $this->createStub(OperatorRoleAccess::class);
        $roles->method('hasAssignedRole')->willReturn($assignedRole);
        $root = new RootAuthorizationPolicy(new ConfiguredRootIdentityRegistry('Root'));
        $authorization = new OperatorAuthorizationService($root, new OperatorPermissionPolicy($root, $roles), $roles);
        $operators = new OperChanServOperatorAccess(new IrcopAccessQueryService($authorization));
        $boundary = $this->createStub(ChanTransactionBoundary::class);
        $boundary->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $history = $this->createMock(ChannelHistoryRepositoryInterface::class);
        $history->expects('creg.accepted' === $acceptReply ? self::once() : self::never())->method('save')->with(self::callback(static fn (ChannelHistory $entry): bool => 'CREG_ACCEPT' === $entry->getAction() && 'Reviewer' === $entry->getPerformedBy()));
        $events = $this->createMock(EventBusInterface::class);
        $events->expects('creg.accepted' === $acceptReply ? self::exactly(2) : self::never())->method('dispatch');
        $activator = new ChannelRegistrationActivator($this->createStub(ChannelLevelRepositoryInterface::class), $this->createStub(ChannelAkickRepositoryInterface::class));
        $mode = $this->createStub(RegistrationModeQuery::class);
        $mode->method('current')->willReturn(RegistrationMode::Approval);
        $register = new RegisterChannelHandler($repository, $activator, $this->createStub(ChannelRegisterThrottlePort::class), $events, $operators, $boundary, maxChannelsPerNick: $maximum, registrationMode: $mode);
        $sessions = new IdentifiedSessionRegistry();
        $sessions->register('' === $sessionUid ? 'UID-' . $nickname : $sessionUid, $nickname);
        $review = new ReviewRegistrationHandler(
            $repository,
            $activator,
            $events,
            new ChannelHistoryService($history),
            $accounts,
            $boundary,
            $operators,
            new NickServIdentifiedRequesterSession($sessions),
            maxChannelsPerNick: $maximum
        );
        $drop = new ChanDropService($repository, $this->createStub(ChanServEventPublisher::class), $this->createStub(ChanAuditSink::class), $this->createStub(ChanServActivitySink::class), $this->createStub(ChanNetworkActions::class), $boundary);
        $messages = new ArrayObject();
        $requester = $this->sender($nickname, true, $ircop);
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels');
        $this->service($repository, $nickname, true, $ircop, 10, $assignedRole, $messages, $review, $drop, $register, accountPort: $accounts, requester: $requester)
            ->dispatch('REGISTER #private request', $requester);
        self::assertSame([$registerReply], iterator_to_array($messages));
        if ("\x0303✔\x03\x0F register.awaiting_approval" !== $registerReply) {
            self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels'));
            $repository->save(RegisteredChannel::createPendingRegistration($at, '#private', 10, 'previous request'));
        }
        $messages = new ArrayObject();
        $this->service(
            $repository,
            'Reviewer',
            true,
            true,
            99,
            true,
            $messages,
            $review,
            $drop,
            allowedPermissions: ['chanserv.registration_review'],
            accountPort: $accounts,
            requester: $online ? $requester : null,
        )->dispatch('CREG ACCEPT #private', $this->sender('Reviewer', true, true));
        self::assertSame([('creg.accepted' === $acceptReply ? "\x0303✔\x03\x0F " : "\x0304✗\x03\x0F ") . $acceptReply], iterator_to_array($messages));
        $this->entityManager->clear();
        $channel = $repository->findByChannelName('#private');
        self::assertNotNull($channel);
        self::assertSame('creg.accepted' !== $acceptReply, $channel->isPendingRegistration());
        self::assertSame(10, $channel->getFounderNickId());
        self::assertCount($approved + $rejected + ('' === $extraState ? 1 : 2), $repository->findByFounderNickId(10));
    }

    #[Test]
    public function nickInfoHidesUnacceptedAssociationsThroughPublicRouting(): void
    {
        $nick = RegisteredNick::createPending('Requester', 'synthetic-hash', null, 'en', new DateTimeImmutable('+1 day'), new DateTimeImmutable('-1 day'));
        $nick->activate();
        $this->entityManager->persist($nick);
        $this->entityManager->flush();
        $repository = new RegisteredChannelDoctrineRepository($this->entityManager);
        $access = new ChannelAccessDoctrineRepository($this->entityManager);
        $approved = RegisteredChannel::register(new DateTimeImmutable('-1 day'), '#approved', $nick->getId(), 'approved');
        $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-1 day'), '#private', $nick->getId(), 'pending');
        $repository->save($approved);
        $repository->save($pending);
        $successor = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-1 day'), '#pending-successor', 99, 'pending');
        $successor->assignSuccessor($nick->getId());
        $repository->save($successor);
        $accessOnly = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-1 day'), '#rejected-access', 99, 'request');
        $accessOnly->rejectRegistration('duplicate', 99, 'Reviewer', new DateTimeImmutable(), null);
        $repository->save($accessOnly);
        $access->save(new ChannelAccess($accessOnly->getId(), $nick->getId(), 100));
        // An existing access row must not accidentally reintroduce an unaccepted founder.
        $access->save(new ChannelAccess($pending->getId(), $nick->getId(), 100));
        foreach (['pending', 'rejected', 'accepted', 'removed'] as $state) {
            if ('rejected' === $state) {
                $pending->rejectRegistration('duplicate', 99, 'Reviewer', new DateTimeImmutable(), new DateTimeImmutable('+1 day'));
                $repository->save($pending);
            } elseif ('accepted' === $state) {
                $repository->delete($pending);
                $pending = RegisteredChannel::createPendingRegistration(new DateTimeImmutable(), '#private', $nick->getId(), 'new request');
                $repository->save($pending);
                $pending->approveRegistration(new DateTimeImmutable());
                $repository->save($pending);
            } elseif ('removed' === $state) {
                $repository->delete($pending);
            }
            $output = $this->nickInfo($repository, $nick->getNickname());
            self::assertStringContainsString('#approved', $output);
            self::assertStringNotContainsString('#pending-successor', $output);
            self::assertStringNotContainsString('#rejected-access', $output);
            if ('accepted' === $state) {
                self::assertStringContainsString('#private', $output);
                self::assertStringContainsString('Founder', $output);
            } else {
                self::assertStringNotContainsString('#private', $output);
            }
        }
        $nick->switchPrivate(true);
        $this->entityManager->flush();
        self::assertStringNotContainsString('#approved', $this->nickInfo($repository, $nick->getNickname(), 'Other'));
    }

    #[Test]
    public function completeSyntheticApprovalLifecyclePreservesNotificationsAuditAndHistory(): void
    {
        $at = new DateTimeImmutable('-1 day');
        $nick = RegisteredNick::createPending('RequesterOper', 'synthetic-hash', null, 'en', new DateTimeImmutable('+1 day'), $at);
        $nick->activate();
        $this->entityManager->persist($nick);
        $this->entityManager->flush();
        $requesterId = $nick->getId();
        $repository = new RegisteredChannelDoctrineRepository($this->entityManager);
        foreach (['#one', '#two', '#three'] as $name) {
            $repository->save(RegisteredChannel::register($at, $name, $requesterId, 'approved'));
        }
        $rejected = RegisteredChannel::createPendingRegistration($at, '#private', $requesterId, 'request');
        $rejected->rejectRegistration('duplicate', 99, 'Reviewer', $at, new DateTimeImmutable('+1 day'));
        $repository->save($rejected);
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findAccountById')->willReturn(new ChanAccountView($requesterId, 'RequesterOper', 'en'));
        $accounts->method('findNicknameById')->willReturn('RequesterOper');
        $accounts->method('findAccountByNick')->willReturnCallback(static fn (string $name): ChanAccountView => new ChanAccountView('Reviewer' === $name ? 99 : $requesterId, $name, 'en'));
        $roles = $this->createStub(OperatorRoleAccess::class);
        $roles->method('hasAssignedRole')->willReturn(true);
        $root = new RootAuthorizationPolicy(new ConfiguredRootIdentityRegistry('Root'));
        $operators = new OperChanServOperatorAccess(new IrcopAccessQueryService(new OperatorAuthorizationService($root, new OperatorPermissionPolicy($root, $roles), $roles)));
        $sessions = new IdentifiedSessionRegistry();
        $sessions->register('UID-RequesterOper', 'RequesterOper');
        $levels = new ChannelLevelDoctrineRepository($this->entityManager);
        $akicks = new ChannelAkickDoctrineRepository($this->entityManager);
        $access = new ChannelAccessDoctrineRepository($this->entityManager);
        $history = new ChannelHistoryDoctrineRepository($this->entityManager);
        $cleanup = new EventDispatcher();
        $cleanup->addSubscriber(new ChanServLevelsChannelDropSubscriber(new CleanupChannelLevelsHandler($levels)));
        $cleanup->addSubscriber(new ChanServAkickChannelDropSubscriber(new CleanupChannelAkickHandler($akicks)));
        $cleanup->addSubscriber(new ChanServAccessChannelDropSubscriber(new CleanupChannelAccessHandler($access)));
        $cleanup->addSubscriber(new ChanServHistoryChannelDropSubscriber(new CleanupChannelHistoryHandler($history)));
        $publisher = $this->createStub(ChanServEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(static function (object $event) use ($cleanup): void { $cleanup->dispatch($event); });
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/memoserv.en.yaml', 'en', 'memoserv');
        $memoAccounts = $this->createStub(MemoUserAccountPort::class);
        $memoAccounts->method('findNicknameById')->willReturn('RequesterOper');
        $memoAccounts->method('getLanguage')->willReturn('en');
        $memoSender = $this->createStub(ServiceMemoSender::class);
        $memoSender->method('getSenderName')->willReturn('ChanServ');
        $memos = new MemoDoctrineRepository($this->entityManager);
        $notifications = new MemoServChannelRegistrationDecisionSubscriber(
            new NotifyChannelRegistrationDecisionHandler($memoAccounts, $memos, new ChannelRegistrationMemoTextAdapter($translator), $memoSender),
            $this->createStub(LoggerInterface::class),
        );
        $published = new class {
            /** @var list<object> */
            public array $events = [];
        };
        $events = $this->createStub(EventBusInterface::class);
        $events->method('dispatch')->willReturnCallback(static function (object $event) use ($notifications, $published): void {
            $published->events[] = $event;
            if ($event instanceof ChannelRegistrationDecisionEvent) {
                $notifications->onDecision($event);
            }
        });
        $boundary = $this->createStub(ChanTransactionBoundary::class);
        $boundary->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $activator = new ChannelRegistrationActivator($levels, $akicks);
        $mode = $this->createStub(RegistrationModeQuery::class);
        $mode->method('current')->willReturn(RegistrationMode::Approval);
        $register = new RegisterChannelHandler($repository, $activator, $this->createStub(ChannelRegisterThrottlePort::class), $events, $operators, $boundary, registrationMode: $mode);
        $review = new ReviewRegistrationHandler($repository, $activator, $events, new ChannelHistoryService($history), $accounts, $boundary, $operators, new NickServIdentifiedRequesterSession($sessions));
        $drop = new ChanDropService($repository, $publisher, $this->createStub(ChanAuditSink::class), $this->createStub(ChanServActivitySink::class), $this->createStub(ChanNetworkActions::class), $boundary);
        $requester = $this->sender('RequesterOper', true, true);
        $run = function (string $input, bool $asRequester = false) use ($repository, $accounts, $requester, $review, $drop, $register, $events): array {
            $messages = new ArrayObject();
            $this->service(
                $repository,
                'Reviewer',
                true,
                true,
                99,
                true,
                $messages,
                $review,
                $drop,
                $register,
                ['chanserv.registration_review', 'chanserv.drop', 'chanserv.drop.force'],
                $events,
                $accounts,
                $requester,
            )->dispatch($input, $asRequester ? $requester : $this->sender('Reviewer', true, true));
            $this->entityManager->clear();

            return iterator_to_array($messages);
        };
        self::assertContains('status.rejected', $run('STATUS #private'));
        self::assertSame(["\x0303✔\x03\x0F drop.force_success"], $run('DROP #private'));
        self::assertNull($repository->findByChannelName('#private'));
        self::assertSame(["\x0303✔\x03\x0F register.awaiting_approval"], $run('REGISTER #private description', true));
        self::assertStringNotContainsString('#private', $this->nickInfo($repository, 'RequesterOper'));
        self::assertSame(["\x0303✔\x03\x0F drop.force_success"], $run('DROP #private'));
        self::assertNull($repository->findByChannelName('#private'));
        self::assertCount(0, $memos->findByTargetNick($requesterId));
        self::assertSame(["\x0303✔\x03\x0F register.awaiting_approval"], $run('REGISTER #private description', true));
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels');
        self::assertSame(["\x0304✗\x03\x0F presentation.error.syntax"], $run('CREG REJECT #private'));
        self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels'));
        self::assertSame(["\x0303✔\x03\x0F creg.rejected"], $run('CREG REJECT #private duplicate'));
        self::assertCount(1, $memos->findByTargetNick($requesterId));
        $rejectedRequest = new RegisteredChannelDoctrineRepository($this->entityManager)->findByChannelName('#private');
        self::assertNotNull($rejectedRequest);
        self::assertCount(0, $history->findByChannelId($rejectedRequest->getId()));
        self::assertStringNotContainsString('#private', $this->nickInfo($repository, 'RequesterOper'));
        self::assertSame(["\x0304✗\x03\x0F register.restricted_until"], $run('REGISTER #private description', true));
        self::assertSame(["\x0303✔\x03\x0F drop.force_success"], $run('DROP #private FORCE'));
        self::assertSame(["\x0303✔\x03\x0F register.awaiting_approval"], $run('REGISTER #private description', true));
        self::assertSame(["\x0303✔\x03\x0F creg.accepted"], $run('CREG ACCEPT #private'));
        $accepted = new RegisteredChannelDoctrineRepository($this->entityManager)->findByChannelName('#private');
        self::assertNotNull($accepted);
        self::assertFalse($accepted->isPendingRegistration());
        $acceptedId = $accepted->getId();
        self::assertCount(1, $history->findByChannelId($acceptedId));
        self::assertSame('CREG_ACCEPT', $history->findByChannelId($acceptedId)[0]->getAction());
        self::assertCount(2, $memos->findByTargetNick($requesterId));
        self::assertStringContainsString('#private', $this->nickInfo($repository, 'RequesterOper'));
        $decisions = array_values(array_filter($published->events, static fn (object $event): bool => $event instanceof ChannelRegistrationDecisionEvent));
        self::assertCount(2, $decisions);
        $outcomes = array_values(array_filter($published->events, static fn (object $event): bool => $event instanceof CommandExecutedEvent));
        self::assertNotEmpty($outcomes);
        $acceptOutcome = $outcomes[array_key_last($outcomes)]->outcome;
        self::assertNotNull($acceptOutcome);
        self::assertTrue($acceptOutcome->success);
        self::assertNotNull($acceptOutcome->auditData);
        self::assertSame('#private', $acceptOutcome->auditData->target);
        self::assertSame(['decision' => 'accepted'], $acceptOutcome->auditData->extra);
        self::assertSame(["\x0303✔\x03\x0F drop.force_success"], $run('DROP #private FORCE'));
        self::assertNull($repository->findByChannelName('#private'));
        self::assertCount(0, $history->findByChannelId($acceptedId));
        self::assertCount(0, $levels->listByChannel($acceptedId));
        self::assertStringNotContainsString('#private', $this->nickInfo($repository, 'RequesterOper'));
        self::assertCount(3, $repository->findByFounderNickId($requesterId));
        self::assertCount(2, $memos->findByTargetNick($requesterId));
    }

    /** @return iterable<string, array{string, int, list<string>, int, bool}> */
    public static function listVisibilityCases(): iterable
    {
        yield 'first page ignores request rows before registered rows' => ['#visible-*', 1, ['#visible-a', '#visible-b'], 4, true];
        yield 'second page retains pending deletion and forbidden' => ['#visible-*', 2, ['#visible-c', '#visible-d'], 4, true];
        yield 'past final page' => ['#visible-*', 3, [], 4, true];
        yield 'pending exact lookup' => ['#visible-00-pending', 1, [], 0, true];
        yield 'rejected exact lookup' => ['#visible-01-rejected', 1, [], 0, true];
        yield 'only request rows match' => ['#visible-0*', 1, [], 0, true];
        yield 'LIST permission remains required' => ['#visible-*', 1, [], 0, false];
    }

    /** @param list<string> $expectedNames */
    #[Test]
    #[DataProvider('listVisibilityCases')]
    public function listExcludesUnacceptedRequestsBeforeCountingAndPagination(string $pattern, int $page, array $expectedNames, int $total, bool $granted): void
    {
        $repository = new RegisteredChannelDoctrineRepository($this->entityManager);
        $at = new DateTimeImmutable('-1 day');
        $active = RegisteredChannel::register($at, '#visible-a', 1, 'active');
        $suspended = RegisteredChannel::register($at, '#visible-b', 1, 'suspended');
        $suspended->suspend('test');
        $deleting = RegisteredChannel::register($at, '#visible-c', 1, 'deleting');
        $deleting->markPendingDeletion($at);
        $forbidden = RegisteredChannel::createForbidden($at, '#visible-d', 'test');
        $pending = RegisteredChannel::createPendingRegistration($at, '#visible-00-pending', 1, 'pending');
        $rejected = RegisteredChannel::createPendingRegistration($at, '#visible-01-rejected', 1, 'rejected');
        $rejected->rejectRegistration('duplicate', 99, 'Reviewer', $at, new DateTimeImmutable('+1 day'));
        foreach ([$active, $suspended, $deleting, $forbidden, $pending, $rejected] as $channel) {
            $repository->save($channel);
        }
        $this->entityManager->clear();
        $accounts = $this->createStub(ChanUserAccountPort::class);
        $accounts->method('findNicknamesByIds')->willReturn([1 => 'Owner']);
        $handler = new ListRegisteredChannelsHandler($repository, $accounts, 2);
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        foreach (['chanserv', 'common'] as $domain) {
            $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/' . $domain . '.en.yaml', 'en', $domain);
        }
        $observed = new class {
            public ?CommandOutcome $outcome = null;
        };
        $events = $this->createStub(EventBusInterface::class);
        $events->method('dispatch')->willReturnCallback(static function (object $event) use ($observed): void {
            if ($event instanceof CommandExecutedEvent) {
                $observed->outcome = $event->outcome;
            }
        });
        $messages = new ArrayObject();
        $service = $this->service(
            $repository,
            'Reviewer',
            true,
            true,
            99,
            true,
            $messages,
            $this->createStub(ReviewRegistrationHandlerInterface::class),
            $this->createStub(ChanDropService::class),
            allowedPermissions: $granted ? ['chanserv.list', 'chanserv.registration_review'] : [],
            eventBus: $events,
            listHandler: $handler,
            translatorOverride: $translator,
        );
        $before = hash('sha256', serialize($this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels ORDER BY id')));
        $service->dispatch('LIST ' . $pattern . ' ' . $page, $this->sender('Reviewer', true, true));
        if (!$granted) {
            self::assertNull($observed->outcome);
            self::assertSame(["\x0304✗\x03\x0F " . $translator->trans('presentation.error.permission_denied', [], 'common')], iterator_to_array($messages));
        } else {
            self::assertNotNull($observed->outcome);
            self::assertTrue($observed->outcome->success);
            self::assertNotNull($observed->outcome->auditData);
            self::assertSame(['page' => $page, 'total' => $total], $observed->outcome->auditData->extra);
            $lines = iterator_to_array($messages);
            self::assertNotEmpty($lines);
            self::assertSame($translator->trans('list.page', ['%page%' => $page, '%pages%' => (int) ceil($total / 2), '%total%' => $total], 'common'), $lines[array_key_last($lines)]);
            $rows = array_values(array_filter($lines, static fn (string $line): bool => str_starts_with($line, '#')));
            self::assertSame($expectedNames, array_map(static fn (string $row): string => explode(' | ', $row)[0], $rows));
            self::assertStringNotContainsString('#visible-00-pending', implode("\n", $rows));
            self::assertStringNotContainsString('#visible-01-rejected', implode("\n", $rows));
            if (1 === $page && '#visible-*' === $pattern) {
                self::assertInstanceOf(ArrayObject::class, $messages);
                $messages->exchangeArray([]);
                $service->dispatch('CREG PENDING', $this->sender('Reviewer', true, true));
                self::assertStringContainsString('#visible-00-pending', implode("\n", iterator_to_array($messages)));
                self::assertStringNotContainsString('#visible-01-rejected', implode("\n", iterator_to_array($messages)));
            }
        }
        self::assertSame($before, hash('sha256', serialize($this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM registered_channels ORDER BY id'))));
    }

    private function nickInfo(RegisteredChannelDoctrineRepository $channels, string $nickname, ?string $viewer = null): string
    {
        $nicks = new RegisteredNickDoctrineRepository($this->entityManager);
        $associations = new NickChannelAssociationQueryService(
            new ChannelAccessDoctrineRepository($this->entityManager),
            $channels,
        );
        $handler = new InfoNickHandler(
            $nicks,
            new ChanServNickAssociatedChannelsAdapter($associations),
            new VhostDisplayResolver(),
        );
        $lookup = $this->createStub(NetworkUserLookupPort::class);
        $messages = new ArrayObject();
        $notifier = $this->createStub(NickServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('NickServ');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use ($messages): void { $messages->append($message); });
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/nickserv.en.yaml', 'en', 'nickserv');
        $language = $this->createStub(UserLanguageQuery::class);
        $language->method('resolveFromAccount')->willReturn('en');
        $language->method('resolve')->willReturn('en');
        $service = new NickServService(
            $this->createStub(AuthorizationContextInterface::class),
            $this->createStub(AuthorizationCheckerInterface::class),
            new NickServCommandRegistry([new InfoCommand($handler, $lookup)]),
            $nicks,
            $language,
            $notifier,
            $this->createStub(UserMessagePreferenceQuery::class),
            $translator,
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            new ServiceNicknameRegistry([]),
            $this->createStub(EventBusInterface::class),
        );
        $service->dispatch('INFO ' . $nickname, $this->sender($viewer ?? $nickname, true, false));

        return implode("\n", iterator_to_array($messages));
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
        ?ChanUserAccountPort $accountPort = null,
        ?SenderView $requester = null,
        ?ListRegisteredChannelsHandlerInterface $listHandler = null,
        ?TranslatorInterface $translatorOverride = null,
        ?ChannelLookupPort $channelLookupOverride = null,
    ): ChanServService {
        if ($channel instanceof RegisteredChannel) {
            $channels = $this->createStub(RegisteredChannelRepositoryInterface::class);
            $channels->method('findByChannelName')->willReturn($channel);
        } else {
            $channels = $channel;
        }
        if (null === $accountPort) {
            $accounts = $this->createStub(ChanUserAccountPort::class);
            $accounts->method('findAccountByNick')->willReturnCallback(static fn (string $nick): ?ChanAccountView => null === $accountId ? null : new ChanAccountView($accountId, $nick, 'en'));
        } else {
            $accounts = $accountPort;
        }
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
        if (null !== $listHandler) {
            $commands[] = new ListCommand($listHandler);
        }
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
        if (null === $translatorOverride) {
            $translator = $this->createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);
        } else {
            $translator = $translatorOverride;
        }
        $preferences = $this->createStub(ChanServUserPresentationPreferences::class);
        $preferences->method('languageFor')->willReturn('en');
        $preferences->method('prefersPrivateMessages')->willReturn(false);
        $authorizationContext = $this->createStub(ChanAuthorizationContextInterface::class);

        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $userLookup->method('findByNick')->willReturn($requester);

        return new ChanServService(
            new ChanServCommandRegistry($commands),
            $channels,
            $accounts,
            $preferences,
            $notifier,
            $translator,
            $channelLookupOverride ?? $this->channelLookup($requester),
            $this->modeSupportProvider(),
            $userLookup,
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

    private function channelLookup(?SenderView $requester = null): ChannelLookupPort
    {
        $lookup = $this->createStub(ChannelLookupPort::class);
        $lookup->method('findByChannelName')->willReturnCallback(static fn (string $channelName): ChannelView => new ChannelView(
            $channelName,
            '+o',
            null,
            1,
            [['uid' => $requester->uid ?? 'UID-Other', 'roleLetter' => 'o', 'prefixLetters' => ['o']]],
        ));

        return $lookup;
    }
}
