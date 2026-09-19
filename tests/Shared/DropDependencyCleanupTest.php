<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Bootstrap\Adapter\Out\Persistence\DoctrineTransactionManager;
use App\ChanServ\Adapter\In\Event\ChanServAccessChannelDropSubscriber;
use App\ChanServ\Adapter\In\Event\ChanServAkickChannelDropSubscriber;
use App\ChanServ\Adapter\In\Event\ChanServHistoryChannelDropSubscriber;
use App\ChanServ\Adapter\In\Event\ChanServLevelsChannelDropSubscriber;
use App\ChanServ\Adapter\In\Event\ChanServNickDropCleanupSubscriber;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChanDoctrineTransactionBoundary;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAccessDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelAkickDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelHistoryDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\ChannelLevelDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Application\Port\Out\ChanServEventPublisher;
use App\ChanServ\Application\Port\Out\NickDropCleanupActivitySink;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
use App\ChanServ\Application\UseCase\CleanupChannelAccess\CleanupChannelAccessHandler;
use App\ChanServ\Application\UseCase\CleanupChannelAkick\CleanupChannelAkickHandler;
use App\ChanServ\Application\UseCase\CleanupChannelHistory\CleanupChannelHistoryHandler;
use App\ChanServ\Application\UseCase\CleanupChannelLevels\CleanupChannelLevelsHandler;
use App\ChanServ\Application\UseCase\CleanupDroppedNick\CleanupDroppedNickDataHandler;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelAkick;
use App\ChanServ\Domain\Entity\ChannelHistory;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\MemoServ\Adapter\In\Event\MemoServChannelDropCleanupSubscriber;
use App\MemoServ\Adapter\In\Event\MemoServNickDropCleanupSubscriber;
use App\MemoServ\Adapter\Out\Persistence\Doctrine\MemoDoctrineRepository;
use App\MemoServ\Adapter\Out\Persistence\Doctrine\MemoIgnoreDoctrineRepository;
use App\MemoServ\Adapter\Out\Persistence\Doctrine\MemoSettingsDoctrineRepository;
use App\MemoServ\Application\UseCase\CleanupChannel\CleanupChannelMemoDataHandler;
use App\MemoServ\Application\UseCase\CleanupNick\CleanupNickMemoDataHandler;
use App\MemoServ\Domain\Entity\Memo;
use App\MemoServ\Domain\Entity\MemoIgnore;
use App\MemoServ\Domain\Entity\MemoSettings;
use App\NickServ\Adapter\In\Event\ForbiddenVhostCleanupSubscriber;
use App\NickServ\Adapter\In\Event\NickHistoryNickDropSubscriber;
use App\NickServ\Adapter\Out\Persistence\Doctrine\ForbiddenVhostDoctrineRepository;
use App\NickServ\Adapter\Out\Persistence\Doctrine\NickHistoryDoctrineRepository;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use App\NickServ\Domain\Entity\ForbiddenVhost;
use App\NickServ\Domain\Entity\NickHistory;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\OperServ\Adapter\In\Event\OperServNickDropCleanupSubscriber;
use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineGlineRepository;
use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineMotdRepository;
use App\OperServ\Adapter\Out\Persistence\Doctrine\OperIrcopDoctrineRepository;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Entity\OperRole;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Proves the non-negotiable drop rule from .agents/persistence.md §8: a definitive
 * nickname or channel drop leaves no dependent rows behind, across bounded contexts.
 */
#[CoversNothing]
#[Group('integration')]
final class DropDependencyCleanupTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function nicknameHardDropLeavesNoDependentRows(): void
    {
        $at = new DateTimeImmutable('2026-09-19 10:00:00');

        $role = OperRole::create(name: 'Admin', description: 'Admin role');
        $this->entityManager->persist($role);
        $this->entityManager->flush();

        $founder = RegisteredNick::createPending('Founder', 'hash', 'founder@example.com', 'en', $at->modify('+1 day'), $at);
        $founder->activate();
        $successor = RegisteredNick::createPending('Successor', 'hash', 'successor@example.com', 'en', $at->modify('+1 day'), $at);
        $successor->activate();
        $this->entityManager->persist($founder);
        $this->entityManager->persist($successor);
        $this->entityManager->flush();

        $founderId = $founder->getId();
        $successorId = $successor->getId();

        $transferred = RegisteredChannel::register($at, '#transferred', $founderId, 'Kept through successor');
        $transferred->assignSuccessor($successorId);
        $dropped = RegisteredChannel::register($at, '#dropped', $founderId, 'No successor');
        $this->entityManager->persist($transferred);
        $this->entityManager->persist($dropped);
        $this->entityManager->flush();

        $transferredId = $transferred->getId();
        $droppedId = $dropped->getId();

        $this->entityManager->persist(new ChannelAccess($transferredId, $successorId, 300));
        $this->entityManager->persist(new ChannelAccess($droppedId, $founderId, 300));
        $this->entityManager->persist(ChannelAkick::create($at, $transferredId, $founderId, '*!*@akick.example'));
        $this->entityManager->persist(new ChannelLevel($transferredId, ChannelLevel::KEY_AUTOOP, 300));
        $this->entityManager->persist(new ChannelLevel($droppedId, ChannelLevel::KEY_AUTOOP, 300));
        $this->entityManager->persist(ChannelHistory::record($at, $transferredId, 'TOPIC', 'Founder', $founderId, 'history.message.topic'));
        $this->entityManager->persist(ChannelHistory::record($at, $droppedId, 'TOPIC', 'Founder', $founderId, 'history.message.topic'));
        $this->entityManager->persist(new Memo(targetNickId: $founderId, targetChannelId: null, senderNickId: $successorId, message: 'to founder', createdAt: $at));
        $this->entityManager->persist(new Memo(targetNickId: null, targetChannelId: $transferredId, senderNickId: $founderId, message: 'from founder', createdAt: $at));
        $this->entityManager->persist(new Memo(targetNickId: null, targetChannelId: $droppedId, senderNickId: $successorId, message: 'to dropped channel', createdAt: $at));
        $this->entityManager->persist(new Memo(targetNickId: null, targetChannelId: $transferredId, senderNickId: $successorId, message: 'stays', createdAt: $at));
        $this->entityManager->persist(new MemoSettings(targetNickId: $founderId, targetChannelId: null, enabled: false));
        $this->entityManager->persist(new MemoSettings(targetNickId: null, targetChannelId: $droppedId, enabled: false));
        $this->entityManager->persist(new MemoIgnore(targetNickId: $successorId, targetChannelId: null, ignoredNickId: $founderId));
        $this->entityManager->persist(new MemoIgnore(targetNickId: null, targetChannelId: $transferredId, ignoredNickId: $founderId));
        $this->entityManager->persist(new MemoIgnore(targetNickId: null, targetChannelId: $droppedId, ignoredNickId: $successorId));
        $this->entityManager->persist(ForbiddenVhost::create('*!*@forbidden.example', $founderId, $at));
        $this->entityManager->persist(NickHistory::record($founderId, 'DROP', 'Oper', null, 'history.message.drop', $at));
        $this->entityManager->persist(OperIrcop::create($at, nickId: $founderId, role: $role));
        $this->entityManager->persist(OperIrcop::create($at, nickId: 999001, role: $role, addedById: $founderId));
        $this->entityManager->flush();

        $glineRepository = new DoctrineGlineRepository($this->entityManager);
        $glineRepository->save('*@gline.example', $founderId, 'spam', $at, null);
        $motdRepository = new DoctrineMotdRepository($this->entityManager);
        $motdRepository->add('MOTD', 'OperServ', MessageDelivery::NonInteractive, $founderId, $at, null);

        $dispatcher = $this->cleanupDispatcher();
        $nickRepository = new RegisteredNickDoctrineRepository($this->entityManager);
        $transactionManager = new DoctrineTransactionManager($this->entityManager);

        $transactionManager->transactional(static function () use ($dispatcher, $nickRepository, $founderId, $at): void {
            $dispatcher->dispatch(new NickDropCleanupEvent($founderId, 'Founder', 'founder', 'manual', $at));

            $droppedNick = $nickRepository->findById($founderId);
            self::assertNotNull($droppedNick);
            $nickRepository->delete($droppedNick);
        });

        $connection = $this->entityManager->getConnection();

        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM registered_nicks WHERE id = ?', [$founderId]));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM registered_nicks WHERE id = ?', [$successorId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM channel_access WHERE nick_id = ?', [$founderId]));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM channel_access WHERE channel_id = ? AND nick_id = ?', [$transferredId, $successorId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM channel_akick WHERE creator_nick_id = ?', [$founderId]));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM channel_akick WHERE channel_id = ?', [$transferredId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM registered_channels WHERE id = ?', [$droppedId]));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM registered_channels WHERE id = ? AND founder_nick_id = ? AND successor_nick_id IS NULL', [$transferredId, $successorId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM channel_levels WHERE channel_id = ?', [$droppedId]));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM channel_levels WHERE channel_id = ?', [$transferredId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM channel_history WHERE channel_id = ?', [$droppedId]));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM channel_history WHERE channel_id = ?', [$transferredId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM memos WHERE target_nick_id = ? OR sender_nick_id = ?', [$founderId, $founderId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM memos WHERE target_channel_id = ?', [$droppedId]));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM memos WHERE target_channel_id = ?', [$transferredId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM memo_settings WHERE target_nick_id = ? OR target_channel_id = ?', [$founderId, $droppedId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM memo_ignores WHERE ignored_nick_id = ? OR target_channel_id = ?', [$founderId, $droppedId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM forbidden_vhosts WHERE created_by_nick_id = ?', [$founderId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM nick_history WHERE nick_id = ?', [$founderId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM oper_ircops WHERE nick_id = ?', [$founderId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM oper_ircops WHERE added_by_id = ?', [$founderId]));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM oper_ircops WHERE nick_id = ?', [999001]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM gline WHERE creator_nick_id = ?', [$founderId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM motd WHERE creator_nick_id = ?', [$founderId]));
    }

    #[Test]
    public function channelHardDropLeavesNoDependentRows(): void
    {
        $at = new DateTimeImmutable('2026-09-19 11:00:00');

        $channel = RegisteredChannel::register($at, '#hard-drop', 555001, 'Dropped for good');
        $this->entityManager->persist($channel);
        $this->entityManager->flush();

        $channelId = $channel->getId();

        $this->entityManager->persist(new ChannelAccess($channelId, 555001, 300));
        $this->entityManager->persist(ChannelAkick::create($at, $channelId, 555001, '*!*@akick.example'));
        $this->entityManager->persist(new ChannelLevel($channelId, ChannelLevel::KEY_AUTOOP, 300));
        $this->entityManager->persist(ChannelHistory::record($at, $channelId, 'TOPIC', 'Founder', 555001, 'history.message.topic'));
        $this->entityManager->persist(new Memo(targetNickId: null, targetChannelId: $channelId, senderNickId: 555001, message: 'memo', createdAt: $at));
        $this->entityManager->persist(new MemoSettings(targetNickId: null, targetChannelId: $channelId, enabled: false));
        $this->entityManager->persist(new MemoIgnore(targetNickId: null, targetChannelId: $channelId, ignoredNickId: 555001));
        $this->entityManager->flush();

        $dispatcher = $this->cleanupDispatcher();
        $channelRepository = new RegisteredChannelDoctrineRepository($this->entityManager);
        $transactionManager = new DoctrineTransactionManager($this->entityManager);

        $transactionManager->transactional(static function () use ($dispatcher, $channelRepository, $channelId, $at): void {
            $dispatcher->dispatch(new ChannelDropCleanupEvent($channelId, $at));

            $droppedChannel = $channelRepository->findByChannelName('#hard-drop');
            self::assertNotNull($droppedChannel);
            $channelRepository->delete($droppedChannel);
        });

        $connection = $this->entityManager->getConnection();

        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM registered_channels WHERE id = ?', [$channelId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM channel_access WHERE channel_id = ?', [$channelId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM channel_akick WHERE channel_id = ?', [$channelId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM channel_levels WHERE channel_id = ?', [$channelId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM channel_history WHERE channel_id = ?', [$channelId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM memos WHERE target_channel_id = ?', [$channelId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM memo_settings WHERE target_channel_id = ?', [$channelId]));
        self::assertSame(0, $connection->fetchOne('SELECT COUNT(*) FROM memo_ignores WHERE target_channel_id = ?', [$channelId]));
    }

    private function cleanupDispatcher(): EventDispatcher
    {
        $dispatcher = new EventDispatcher();

        $accessRepository = new ChannelAccessDoctrineRepository($this->entityManager);
        $akickRepository = new ChannelAkickDoctrineRepository($this->entityManager);
        $historyRepository = new ChannelHistoryDoctrineRepository($this->entityManager);
        $levelRepository = new ChannelLevelDoctrineRepository($this->entityManager);
        $channelRepository = new RegisteredChannelDoctrineRepository($this->entityManager);
        $memoRepository = new MemoDoctrineRepository($this->entityManager);
        $memoIgnoreRepository = new MemoIgnoreDoctrineRepository($this->entityManager);
        $memoSettingsRepository = new MemoSettingsDoctrineRepository($this->entityManager);
        $transactionManager = new DoctrineTransactionManager($this->entityManager);

        $dispatcher->addSubscriber(new ChanServAccessChannelDropSubscriber(new CleanupChannelAccessHandler($accessRepository)));
        $dispatcher->addSubscriber(new ChanServHistoryChannelDropSubscriber(new CleanupChannelHistoryHandler($historyRepository)));
        $dispatcher->addSubscriber(new ChanServLevelsChannelDropSubscriber(new CleanupChannelLevelsHandler($levelRepository)));
        $dispatcher->addSubscriber(new ChanServAkickChannelDropSubscriber(new CleanupChannelAkickHandler($akickRepository)));
        $dispatcher->addSubscriber(new MemoServChannelDropCleanupSubscriber(new CleanupChannelMemoDataHandler(
            $memoRepository,
            $memoIgnoreRepository,
            $memoSettingsRepository,
        )));
        $dispatcher->addSubscriber(new MemoServNickDropCleanupSubscriber(new CleanupNickMemoDataHandler(
            $memoRepository,
            $memoIgnoreRepository,
            $memoSettingsRepository,
        )));
        $dispatcher->addSubscriber(new ForbiddenVhostCleanupSubscriber(new ForbiddenVhostDoctrineRepository($this->entityManager)));
        $dispatcher->addSubscriber(new NickHistoryNickDropSubscriber(new NickHistoryDoctrineRepository($this->entityManager)));
        $dispatcher->addSubscriber(new OperServNickDropCleanupSubscriber(
            new OperIrcopDoctrineRepository($this->entityManager),
            new DoctrineGlineRepository($this->entityManager),
            new DoctrineMotdRepository($this->entityManager),
        ));
        $dispatcher->addSubscriber(new ChanServNickDropCleanupSubscriber(new CleanupDroppedNickDataHandler(
            $accessRepository,
            $akickRepository,
            $channelRepository,
            new class($dispatcher) implements ChanServEventPublisher {
                public function __construct(private readonly EventDispatcher $dispatcher) {}

                public function publish(object $event): void
                {
                    $this->dispatcher->dispatch($event);
                }
            },
            new ChanDoctrineTransactionBoundary($transactionManager),
            $this->createStub(NickDropCleanupActivitySink::class),
        )));

        return $dispatcher;
    }
}
