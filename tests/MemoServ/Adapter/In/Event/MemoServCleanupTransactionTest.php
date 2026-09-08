<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Event;

use App\Bootstrap\Adapter\Out\Persistence\DoctrineTransactionManager;
use App\ChanServ\Application\PublishedEvent\ChannelDropCleanupEvent;
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
use App\NickServ\Application\PublishedEvent\NickDropCleanupEvent;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[CoversClass(MemoServNickDropCleanupSubscriber::class)]
#[CoversClass(MemoServChannelDropCleanupSubscriber::class)]
#[CoversClass(CleanupNickMemoDataHandler::class)]
#[CoversClass(CleanupChannelMemoDataHandler::class)]
#[CoversClass(MemoDoctrineRepository::class)]
#[CoversClass(MemoIgnoreDoctrineRepository::class)]
#[CoversClass(MemoSettingsDoctrineRepository::class)]
#[CoversClass(DoctrineTransactionManager::class)]
#[Group('integration')]
final class MemoServCleanupTransactionTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function nickCleanupRollsBackWithTheOwningDropTransaction(): void
    {
        $at = new DateTimeImmutable('2026-09-07 08:00:00');
        $this->entityManager->persist(new Memo(targetNickId: 11, targetChannelId: null, senderNickId: 22, message: 'target', createdAt: $at));
        $this->entityManager->persist(new Memo(targetNickId: null, targetChannelId: 33, senderNickId: 11, message: 'sender', createdAt: $at));
        $this->entityManager->persist(new MemoIgnore(targetNickId: 11, targetChannelId: null, ignoredNickId: 22));
        $this->entityManager->persist(new MemoIgnore(targetNickId: null, targetChannelId: 33, ignoredNickId: 11));
        $this->entityManager->persist(new MemoSettings(targetNickId: 11, targetChannelId: null, enabled: false));
        $this->entityManager->flush();

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->nickCleanupSubscriber());
        $transactionManager = new DoctrineTransactionManager($this->entityManager);

        try {
            $transactionManager->transactional(static function () use ($dispatcher, $at): never {
                $dispatcher->dispatch(new NickDropCleanupEvent(11, 'Dropped', 'dropped', 'manual', $at));

                throw new RuntimeException('provider deletion failed');
            });
            self::fail('The simulated provider failure must escape the transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('provider deletion failed', $exception->getMessage());
        }

        $connection = $this->entityManager->getConnection();
        self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM memos WHERE target_nick_id = 11 OR sender_nick_id = 11'));
        self::assertSame(2, $connection->fetchOne('SELECT COUNT(*) FROM memo_ignores WHERE target_nick_id = 11 OR ignored_nick_id = 11'));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM memo_settings WHERE target_nick_id = 11'));
    }

    #[Test]
    public function channelCleanupRollsBackWithTheOwningDropTransaction(): void
    {
        $at = new DateTimeImmutable('2026-09-07 08:00:00');
        $this->entityManager->persist(new Memo(targetNickId: null, targetChannelId: 44, senderNickId: 22, message: 'channel', createdAt: $at));
        $this->entityManager->persist(new MemoIgnore(targetNickId: null, targetChannelId: 44, ignoredNickId: 22));
        $this->entityManager->persist(new MemoSettings(targetNickId: null, targetChannelId: 44, enabled: false));
        $this->entityManager->flush();

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber($this->channelCleanupSubscriber());
        $transactionManager = new DoctrineTransactionManager($this->entityManager);

        try {
            $transactionManager->transactional(static function () use ($dispatcher): never {
                $dispatcher->dispatch(new ChannelDropCleanupEvent(44));

                throw new RuntimeException('provider deletion failed');
            });
            self::fail('The simulated provider failure must escape the transaction.');
        } catch (RuntimeException $exception) {
            self::assertSame('provider deletion failed', $exception->getMessage());
        }

        $connection = $this->entityManager->getConnection();
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM memos WHERE target_channel_id = 44'));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM memo_ignores WHERE target_channel_id = 44'));
        self::assertSame(1, $connection->fetchOne('SELECT COUNT(*) FROM memo_settings WHERE target_channel_id = 44'));
    }

    private function nickCleanupSubscriber(): MemoServNickDropCleanupSubscriber
    {
        return new MemoServNickDropCleanupSubscriber(new CleanupNickMemoDataHandler(
            new MemoDoctrineRepository($this->entityManager),
            new MemoIgnoreDoctrineRepository($this->entityManager),
            new MemoSettingsDoctrineRepository($this->entityManager),
        ));
    }

    private function channelCleanupSubscriber(): MemoServChannelDropCleanupSubscriber
    {
        return new MemoServChannelDropCleanupSubscriber(new CleanupChannelMemoDataHandler(
            new MemoDoctrineRepository($this->entityManager),
            new MemoIgnoreDoctrineRepository($this->entityManager),
            new MemoSettingsDoctrineRepository($this->entityManager),
        ));
    }
}
