<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\In\Maintenance;

use App\NickServ\Adapter\In\Maintenance\PurgeInactiveNicknamesTask;
use App\NickServ\Adapter\In\Maintenance\PurgePendingDeletionNicknamesTask;
use App\NickServ\Adapter\Out\Persistence\Doctrine\RegisteredNickDoctrineRepository;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Service\NickDropService;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\NickServ\Support\NegotiatedCaseMapping;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Doctrine unsets the id of a removed entity on flush, so a post-drop failure must not read it back.
 */
#[CoversClass(PurgeInactiveNicknamesTask::class)]
#[CoversClass(PurgePendingDeletionNicknamesTask::class)]
final class PurgeTasksPostDropFailureDoctrineTest extends DoctrineIntegrationTestCase
{
    private RegisteredNickDoctrineRepository $repository;

    #[Test]
    public function anInactiveNickWhoseDropCommittedBeforeAFailureIsLoggedAsDroppedAndTheBatchContinues(): void
    {
        $goneId = $this->persistInactive('Gone', 'gone@example.com');
        $this->persistInactive('Healthy', 'healthy@example.com');
        $failure = new \RuntimeException('force rename failed');
        $dropped = [];
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('dropNick')->willReturnCallback(function (RegisteredNick $nick) use ($failure, &$dropped): void {
            $dropped[] = $nick->getNickname();
            $this->entityManager->remove($nick);
            $this->entityManager->flush();
            if ('Gone' === $nick->getNickname()) {
                throw $failure;
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            sprintf('Maintenance [nickserv.purge_inactive_nicknames]: dropped inactive nick Gone (id %d), but a post-drop step failed.', $goneId),
            ['exception' => $failure],
        );

        new PurgeInactiveNicknamesTask($this->repository, $dropService, $logger, $this->clock(), 3600, 30, $this->entityManager)->run();

        self::assertSame(['Gone', 'Healthy'], $dropped);
        self::assertSame([], $this->repository->findRegisteredInactiveSince($this->now()->modify('-30 days')));
    }

    #[Test]
    public function aPendingDeletionNickWhoseDropCommittedBeforeAFailureIsLoggedAsDroppedAndTheBatchContinues(): void
    {
        $goneId = $this->persistPendingDeletion('Gone', 'gone@example.com');
        $this->persistPendingDeletion('Healthy', 'healthy@example.com');
        $failure = new \RuntimeException('drop event subscriber failed');
        $dropped = [];
        $dropService = $this->createStub(NickDropService::class);
        $dropService->method('hardDropNick')->willReturnCallback(function (RegisteredNick $nick) use ($failure, &$dropped): void {
            $dropped[] = $nick->getNickname();
            $this->entityManager->remove($nick);
            $this->entityManager->flush();
            if ('Gone' === $nick->getNickname()) {
                throw $failure;
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            sprintf('Maintenance [nickserv.purge_pending_deletion_nicknames]: dropped pending-deletion nick Gone (id %d), but a post-drop step failed.', $goneId),
            ['exception' => $failure],
        );

        new PurgePendingDeletionNicknamesTask($this->repository, $dropService, $logger, $this->clock(), 3600, 7, $this->entityManager)->run();

        self::assertSame(['Gone', 'Healthy'], $dropped);
        self::assertSame([], $this->repository->findPendingDeletionBefore($this->now()->modify('-7 days')));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new RegisteredNickDoctrineRepository($this->entityManager, NegotiatedCaseMapping::network());
    }

    private function persistInactive(string $nickname, string $email): int
    {
        $nick = $this->registered($nickname, $email);
        $nick->markSeen($this->now()->modify('-60 days'));

        return $this->persist($nick);
    }

    private function persistPendingDeletion(string $nickname, string $email): int
    {
        $nick = $this->registered($nickname, $email);
        $nick->markPendingDeletion($this->now()->modify('-8 days'));

        return $this->persist($nick);
    }

    private function persist(RegisteredNick $nick): int
    {
        $this->repository->save($nick);
        $this->entityManager->flush();
        $id = $nick->getId();
        $this->entityManager->clear();

        return $id;
    }

    private function registered(string $nickname, string $email): RegisteredNick
    {
        $nick = RegisteredNick::createPending($nickname, 'hash', $email, 'en', $this->now()->modify('+1 day'), $this->now()->modify('-90 days'));
        $nick->activate();

        return $nick;
    }

    private function clock(): Clock
    {
        $clock = $this->createStub(Clock::class);
        $clock->method('now')->willReturn($this->now());

        return $clock;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-06 12:00:00 UTC');
    }
}
