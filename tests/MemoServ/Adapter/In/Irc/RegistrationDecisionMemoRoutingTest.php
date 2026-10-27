<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChannelLevelRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\PublishedEvent\ChannelRegistrationDecisionEvent;
use App\ChanServ\Application\Service\ChannelHistoryService;
use App\ChanServ\Application\Service\ChannelRegistrationActivator;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistration;
use App\ChanServ\Application\UseCase\ReviewRegistration\ReviewRegistrationHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Adapter\In\Event\MemoServChannelRegistrationDecisionSubscriber;
use App\MemoServ\Adapter\In\Irc\Command\ListCommand;
use App\MemoServ\Adapter\In\Irc\Command\ReadCommand;
use App\MemoServ\Adapter\In\Irc\MemoAuthorizationCheckerInterface;
use App\MemoServ\Adapter\In\Irc\MemoAuthorizationContextInterface;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Adapter\In\Irc\MemoServService;
use App\MemoServ\Adapter\In\Irc\MemoServUserPresentationPreferences;
use App\MemoServ\Adapter\Out\Irc\ConfiguredChanServMemoSender;
use App\MemoServ\Adapter\Out\Persistence\Doctrine\MemoDoctrineRepository;
use App\MemoServ\Adapter\Out\Translation\ChannelRegistrationMemoTextAdapter;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\List\ListMemosHandler;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecision;
use App\MemoServ\Application\UseCase\NotifyChannelRegistration\NotifyChannelRegistrationDecisionHandler;
use App\MemoServ\Application\UseCase\Read\ReadMemoHandler;
use App\Shared\Application\Port\EventBusInterface;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function dirname;
use function implode;

#[CoversNothing]
final class RegistrationDecisionMemoRoutingTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function acceptedAndRejectedMemosShowConfiguredChanServThroughListAndRead(): void
    {
        $accounts = $this->createStub(MemoUserAccountPort::class);
        $accounts->method('findNicknameById')->willReturnCallback(static fn (int $id): ?string => 12 === $id ? 'Requester' : (7 === $id ? 'Operator' : null));
        $accounts->method('getLanguage')->willReturn('es');
        $accounts->method('findAccountByNick')->willReturn(new MemoAccountView(12, 'Requester', 'es'));
        $channels = $this->createStub(MemoChannelPort::class);
        $repository = new MemoDoctrineRepository($this->entityManager);
        $registry = new ServiceNicknameRegistry([new class implements ServiceNicknameProviderInterface {
            public function getServiceKey(): string
            {
                return 'chanserv';
            }

            public function getNickname(): string
            {
                return 'ChannelBot';
            }
        }]);
        $notificationHandler = new NotifyChannelRegistrationDecisionHandler(
            $accounts,
            $repository,
            new ChannelRegistrationMemoTextAdapter($this->createTranslator()),
            new ConfiguredChanServMemoSender($registry),
        );

        foreach ([true, false] as $accepted) {
            $notificationHandler->handle(new NotifyChannelRegistrationDecision(
                44,
                '#example',
                12,
                7,
                'SecretOperator',
                $accepted,
                'canal duplicado',
                new DateTimeImmutable('2026-10-28T14:35:00+02:00'),
                new DateTimeImmutable('2026-10-27T12:35:00+00:00'),
            ));
        }

        $this->entityManager->clear();
        $repository->deleteAllForNick(7);
        self::assertCount(2, $repository->findByTargetNick(12));

        $messages = [];
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('MemoServ');
        $notifier->method('getServiceKey')->willReturn('memoserv');
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message, string $type) use (&$messages): void {
            $messages[] = $message;
        });
        $preferences = $this->createStub(MemoServUserPresentationPreferences::class);
        $preferences->method('languageFor')->willReturn('es');
        $preferences->method('prefersPrivateMessages')->willReturn(false);
        $authorization = $this->createStub(MemoAuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturn(true);
        $service = new MemoServService(
            new MemoServCommandRegistry([
                new ListCommand(new ListMemosHandler($accounts, $channels, $repository)),
                new ReadCommand(new ReadMemoHandler($accounts, $channels, $repository)),
            ]),
            $accounts,
            $preferences,
            $notifier,
            $preferences,
            $this->createTranslator(),
            $registry,
            $this->createStub(MemoAuthorizationContextInterface::class),
            $authorization,
            $this->createStub(EventBusInterface::class),
            defaultLanguage: 'es',
            defaultTimezone: 'Pacific/Honolulu',
        );

        foreach (['LIST', 'READ 1', 'READ 2'] as $command) {
            $messages = [];
            $service->dispatch($command, new SenderView('U', 'Requester', 'ident', 'host', 'cloak', 'ip', true));
            $rendered = implode("\n", $messages);
            self::assertStringContainsString('ChannelBot', $rendered, $command);
            self::assertStringNotContainsString('SecretOperator', $rendered, $command);
            if ('READ 2' === $command) {
                self::assertStringContainsString('28/10/2026 12:35 UTC', $rendered);
                self::assertStringContainsString('Razón: canal duplicado', $rendered);
            }
        }

        $notificationHandler->handle(new NotifyChannelRegistrationDecision(
            44,
            '#example',
            12,
            7,
            'SecretOperator',
            false,
            'indefinida',
            null,
            new DateTimeImmutable('2026-10-27T13:35:00+00:00'),
        ));
        self::assertStringNotContainsString('Disponible', $repository->findByTargetNick(12)[2]->getMessage());
        $repository->deleteAllForNick(12);
        self::assertSame([], $repository->findByTargetNick(12));
    }

    #[Test]
    public function missingServiceIdentityDoesNotRollbackAcceptedOrRejectedRegistration(): void
    {
        $memoAccounts = $this->createStub(MemoUserAccountPort::class);
        $memoAccounts->method('findNicknameById')->willReturn('Requester');
        $memoAccounts->method('getLanguage')->willReturn('es');
        $memoRepository = new MemoDoctrineRepository($this->entityManager);
        $notifyHandler = new NotifyChannelRegistrationDecisionHandler(
            $memoAccounts,
            $memoRepository,
            new ChannelRegistrationMemoTextAdapter($this->createTranslator()),
            new ConfiguredChanServMemoSender(new ServiceNicknameRegistry([])),
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::exactly(2))->method('warning');
        $subscriber = new MemoServChannelRegistrationDecisionSubscriber($notifyHandler, $logger);
        $events = $this->createStub(EventBusInterface::class);
        $events->method('dispatch')->willReturnCallback(static function (object $event) use ($subscriber): void {
            if ($event instanceof ChannelRegistrationDecisionEvent) {
                $subscriber->onDecision($event);
            }
        });
        $channels = new RegisteredChannelDoctrineRepository($this->entityManager);
        $activator = new ChannelRegistrationActivator(
            $this->createStub(ChannelLevelRepositoryInterface::class),
            $this->createStub(ChannelAkickRepositoryInterface::class),
        );
        $transaction = $this->createStub(ChanTransactionBoundary::class);
        $transaction->method('transactional')->willReturnCallback(static fn (callable $operation): mixed => $operation());
        $channelAccounts = $this->createStub(ChanUserAccountPort::class);
        $channelAccounts->method('findAccountById')->willReturn(new ChanAccountView(12, 'Requester', 'es'));
        $reviewHandler = new ReviewRegistrationHandler(
            $channels,
            $activator,
            $events,
            new ChannelHistoryService($this->createStub(ChannelHistoryRepositoryInterface::class)),
            $channelAccounts,
            $transaction,
        );

        foreach ([true, false] as $accepted) {
            $name = $accepted ? '#accepted' : '#rejected';
            $channels->save(RegisteredChannel::createPendingRegistration(
                new DateTimeImmutable('2026-10-26T12:00:00+00:00'),
                $name,
                12,
                'request',
            ));
            $result = $reviewHandler->handle(new ReviewRegistration(
                $name,
                true,
                7,
                'Operator',
                new DateTimeImmutable('2026-10-27T12:00:00+00:00'),
                $accepted ? null : 'reason',
                true,
            ));

            self::assertSame($accepted ? 'Accepted' : 'Rejected', $result->outcome->name);
            $this->entityManager->clear();
            $persisted = $channels->findByChannelName($name);
            self::assertNotNull($persisted);
            self::assertSame($accepted, !$persisted->isRejectedRegistration());
        }

        self::assertSame([], $memoRepository->findByTargetNick(12));
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = new Translator('es');
        $translator->addLoader('irc', new IrcYamlTranslationLoader());
        $translator->addResource('irc', dirname(__DIR__, 5) . '/translations/memoserv.es.yaml', 'es', 'memoserv');

        return $translator;
    }
}
