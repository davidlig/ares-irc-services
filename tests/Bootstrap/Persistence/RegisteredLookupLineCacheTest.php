<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Persistence;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelDoctrineRepository;
use App\ChanServ\Adapter\Out\Persistence\Doctrine\RegisteredChannelLookupCacheClearSubscriber;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\In\Event\DoctrineIdentityMapClearSubscriber;
use App\Irc\Application\PublishedEvent\IrcMessageProcessingFinishedEvent;
use App\Irc\Application\PublishedEvent\IrcMessageProcessingStartedEvent;
use App\Irc\Application\PublishedEvent\NetworkConnectionLostEvent;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickLookupCacheClearSubscriber;
use App\NickServ\Domain\Entity\RegisteredNick;
use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Logging\Middleware;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function count;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function sys_get_temp_dir;

#[CoversClass(RegisteredChannelDoctrineRepository::class)]
#[CoversClass(RegisteredChannelLookupCacheClearSubscriber::class)]
#[CoversClass(RegisteredNickDoctrineRepository::class)]
#[CoversClass(RegisteredNickLookupCacheClearSubscriber::class)]
final class RegisteredLookupLineCacheTest extends TestCase
{
    #[Test]
    public function cacheSubscribersDeclareTheirLifecycleEvents(): void
    {
        $expected = [
            IrcMessageProcessingStartedEvent::class => ['onIrcMessageProcessingStarted', 1024],
            IrcMessageProcessingFinishedEvent::class => ['onIrcMessageProcessingFinished', -256],
            NetworkConnectionLostEvent::class => 'onNetworkConnectionLost',
        ];
        self::assertSame($expected, RegisteredChannelLookupCacheClearSubscriber::getSubscribedEvents());
        self::assertSame($expected, RegisteredNickLookupCacheClearSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function channelLookupsMemoizeHitsAndMissesUntilMessageBoundaryAndInvalidateOnWrites(): void
    {
        [$entityManager, $logger] = $this->createEntityManager();
        $repository = new RegisteredChannelDoctrineRepository($entityManager);
        $subscriber = new DoctrineIdentityMapClearSubscriber($entityManager);
        $cacheSubscriber = new RegisteredChannelLookupCacheClearSubscriber();

        $logger->enabled = true;
        $cacheSubscriber->onIrcMessageProcessingStarted();
        self::assertNull($repository->findByChannelName('#missing'));
        self::assertNull($repository->findByChannelName('#MISSING'));
        self::assertSame(1, $this->selectCount($logger, 'registered_channels'));

        $cacheSubscriber->onIrcMessageProcessingFinished();
        $subscriber->onIrcMessageProcessed();
        self::assertNull($repository->findByChannelName('#missing'));
        self::assertNull($repository->findByChannelName('#MISSING'));
        self::assertSame(3, $this->selectCount($logger, 'registered_channels'));

        $cacheSubscriber->onIrcMessageProcessingStarted();
        self::assertNull($repository->findByChannelName('#Created'));
        $repository->save(RegisteredChannel::register(new DateTimeImmutable(), '#Created', 1, 'Created'));
        $found = $repository->findByChannelName('#created');
        self::assertNotNull($found);
        self::assertSame($found, $repository->findByChannelName('#CREATED'));
        self::assertSame(5, $this->selectCount($logger, 'registered_channels'));

        $entityManager->clear();
        $refreshed = $repository->findByChannelName('#created');
        self::assertNotNull($refreshed);
        self::assertNotSame($found, $refreshed);
        $found = $refreshed;
        self::assertSame(6, $this->selectCount($logger, 'registered_channels'));

        $cacheSubscriber->onIrcMessageProcessingFinished();
        $subscriber->onIrcMessageProcessed();
        $cacheSubscriber->onIrcMessageProcessingStarted();
        $nextLineFound = $repository->findByChannelName('#CREATED');
        self::assertSame($found->getId(), $nextLineFound->getId());
        self::assertNotSame($found, $nextLineFound);
        self::assertSame(7, $this->selectCount($logger, 'registered_channels'));

        $repository->delete($nextLineFound);
        self::assertNull($repository->findByChannelName('#created'));
        self::assertSame(8, $this->selectCount($logger, 'registered_channels'));

        $cacheSubscriber->onNetworkConnectionLost();
        self::assertNull($repository->findByChannelName('#created'));
        self::assertNull($repository->findByChannelName('#CREATED'));
        self::assertSame(10, $this->selectCount($logger, 'registered_channels'));
        $logger->enabled = false;
        $entityManager->close();
    }

    #[Test]
    public function nickLookupsMemoizeHitsAndMissesUntilMessageBoundaryAndInvalidateOnWrites(): void
    {
        [$entityManager, $logger] = $this->createEntityManager();
        $repository = new RegisteredNickDoctrineRepository($entityManager);
        $subscriber = new DoctrineIdentityMapClearSubscriber($entityManager);
        $cacheSubscriber = new RegisteredNickLookupCacheClearSubscriber();

        $logger->enabled = true;
        $cacheSubscriber->onIrcMessageProcessingStarted();
        self::assertNull($repository->findByNick('Missing'));
        self::assertNull($repository->findByNick('MISSING'));
        self::assertSame(1, $this->selectCount($logger, 'registered_nicks'));

        $cacheSubscriber->onIrcMessageProcessingFinished();
        $subscriber->onIrcMessageProcessed();
        self::assertNull($repository->findByNick('missing'));
        self::assertNull($repository->findByNick('MISSING'));
        self::assertSame(3, $this->selectCount($logger, 'registered_nicks'));

        $cacheSubscriber->onIrcMessageProcessingStarted();
        self::assertNull($repository->findByNick('Created'));
        $repository->save($this->registeredNick('Created'));
        $found = $repository->findByNick('created');
        self::assertNotNull($found);
        self::assertSame($found, $repository->findByNick('CREATED'));
        self::assertSame(5, $this->selectCount($logger, 'registered_nicks'));

        $entityManager->clear();
        $refreshed = $repository->findByNick('created');
        self::assertNotNull($refreshed);
        self::assertNotSame($found, $refreshed);
        $found = $refreshed;
        self::assertSame(6, $this->selectCount($logger, 'registered_nicks'));

        $cacheSubscriber->onIrcMessageProcessingFinished();
        $subscriber->onIrcMessageProcessed();
        $cacheSubscriber->onIrcMessageProcessingStarted();
        $nextLineFound = $repository->findByNick('CREATED');
        self::assertSame($found->getId(), $nextLineFound->getId());
        self::assertNotSame($found, $nextLineFound);
        self::assertSame(7, $this->selectCount($logger, 'registered_nicks'));

        $repository->delete($nextLineFound);
        self::assertNull($repository->findByNick('Created'));
        self::assertSame(8, $this->selectCount($logger, 'registered_nicks'));

        $cacheSubscriber->onNetworkConnectionLost();
        self::assertNull($repository->findByNick('Created'));
        self::assertNull($repository->findByNick('CREATED'));
        self::assertSame(10, $this->selectCount($logger, 'registered_nicks'));
        $logger->enabled = false;
        $entityManager->close();
    }

    protected function tearDown(): void
    {
        RegisteredChannelDoctrineRepository::resetLookupCache();
        RegisteredNickDoctrineRepository::resetLookupCache();

        parent::tearDown();
    }

    /** @return array{EntityManager, QueryCaptureLogger} */
    private function createEntityManager(): array
    {
        $config = new Configuration();
        $config->setProxyDir(sys_get_temp_dir());
        $config->setProxyNamespace('RegisteredLookupLineCacheProxies');
        $config->setMetadataDriverImpl(new SimplifiedXmlDriver([
            __DIR__ . '/../../../config/doctrine/nickserv' => 'App\\NickServ\\Domain\\Entity',
            __DIR__ . '/../../../config/doctrine/chanserv' => 'App\\ChanServ\\Domain\\Entity',
        ]));
        $logger = new QueryCaptureLogger();
        $config->setMiddlewares([new Middleware($logger)]);
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $entityManager = new EntityManager($connection, $config);
        new SchemaTool($entityManager)->createSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return [$entityManager, $logger];
    }

    private function selectCount(QueryCaptureLogger $logger, string $table): int
    {
        return count(array_filter($logger->queries, static fn (array $query): bool => str_starts_with($query['sql'], 'SELECT')
            && str_contains($query['sql'], $table)));
    }

    private function registeredNick(string $nickname): RegisteredNick
    {
        $nick = RegisteredNick::createPending(
            $nickname,
            '$argon2id$v=19$m=65536,t=4,p=1$test$test',
            strtolower($nickname) . '@example.com',
            'en',
            new DateTimeImmutable('+24 hours'),
            new DateTimeImmutable(),
        );
        $nick->activate();

        return $nick;
    }
}
