<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Adapter\Out\Persistence\Doctrine\DoctrineRescueCredentialPersistence;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DoctrineRescueCredentialPersistence::class)]
final class DoctrineRescueCredentialPersistenceTest extends DoctrineIntegrationTestCase
{
    private function account(string $name = 'Rescue'): RegisteredNick
    {
        $nick = RegisteredNick::createPending($name, 'old-password', strtolower($name) . '@example.com', 'en', new DateTimeImmutable('2026-01-02'), new DateTimeImmutable('2026-01-01'));
        $this->entityManager->persist($nick);
        $this->entityManager->flush();

        return $nick;
    }

    #[Test]
    public function confirmationActivatesAndSynchronizesWithoutAllowingStaleMutation(): void
    {
        $nick = $this->account();
        $store = new DoctrineRescueCredentialPersistence($this->entityManager);
        $now = new DateTimeImmutable('2026-01-01');
        $hash = str_repeat('a', 64);
        self::assertTrue($store->confirm($nick->getId(), NickStatus::Pending, null, $hash, $now));
        self::assertTrue($nick->isRegistered());
        self::assertNull($nick->getExpiresAt());
        self::assertSame($hash, $nick->getRescueTokenHash());
        self::assertFalse($store->confirm($nick->getId(), NickStatus::Pending, null, str_repeat('b', 64), $now));
        self::assertFalse($store->confirm(999, NickStatus::Registered, null, $hash, $now));
        $this->entityManager->flush();
        self::assertSame($hash, $this->entityManager->getConnection()->fetchOne('SELECT token FROM registered_nicks WHERE id = ?', [$nick->getId()]));
    }

    #[Test]
    public function activeMigrationAndRecoveryAreCompareAndSwap(): void
    {
        $nick = $this->account();
        $nick->activate();
        $this->entityManager->flush();
        $store = new DoctrineRescueCredentialPersistence($this->entityManager);
        $now = new DateTimeImmutable('2026-01-01');
        $hash = str_repeat('a', 64);
        self::assertTrue($store->confirm($nick->getId(), NickStatus::Registered, null, $hash, $now));
        self::assertFalse($store->confirm($nick->getId(), NickStatus::Registered, null, str_repeat('b', 64), $now));
        self::assertFalse($store->rotateAfterRecovery($nick->getId(), str_repeat('c', 64), 'new-password', str_repeat('b', 64), $now));
        self::assertTrue($store->rotateAfterRecovery($nick->getId(), $hash, 'new-password', str_repeat('b', 64), $now));
        self::assertSame('new-password', $nick->getPasswordHash());
        self::assertSame(str_repeat('b', 64), $nick->getRescueTokenHash());
        self::assertFalse($store->rotateAfterRecovery($nick->getId(), $hash, 'other-password', str_repeat('c', 64), $now));
    }

    #[Test]
    public function expiredAndDisabledAccountsStayUnchanged(): void
    {
        $store = new DoctrineRescueCredentialPersistence($this->entityManager);
        $now = new DateTimeImmutable('2026-01-02');
        $hash = str_repeat('a', 64);
        $nick = $this->account();
        self::assertFalse($store->confirm($nick->getId(), NickStatus::Pending, null, $hash, $now));
        self::assertTrue($nick->isPending());
        self::assertNull($nick->getRescueTokenHash());
        foreach ([NickStatus::Forbidden, NickStatus::Suspended, NickStatus::PendingDeletion] as $status) {
            self::assertFalse($store->confirm($nick->getId(), $status, null, $hash, $now));
        }
        $nick->activate();
        $nick->replaceRescueTokenHash($hash);
        $nick->suspend('reason');
        $this->entityManager->flush();
        self::assertFalse($store->rotateAfterRecovery($nick->getId(), $hash, 'new', str_repeat('b', 64), $now));
        self::assertSame('old-password', $nick->getPasswordHash());
    }

    #[Test]
    public function malformedStoredHashIsRejected(): void
    {
        $store = new DoctrineRescueCredentialPersistence($this->entityManager);
        $this->expectException(InvalidArgumentException::class);
        $store->confirm(1, NickStatus::Registered, null, 'plaintext', new DateTimeImmutable('2026-01-01'));
    }

    #[Test]
    public function emailConfirmationRetainsPersistentHash(): void
    {
        $nick = $this->account();
        $hash = str_repeat('a', 64);
        $nick->replaceRescueTokenHash($hash);
        $this->entityManager->flush();
        $store = new DoctrineRescueCredentialPersistence($this->entityManager);
        self::assertFalse($store->confirm($nick->getId(), NickStatus::Pending, str_repeat('b', 64), $hash, new DateTimeImmutable('2026-01-01')));
        self::assertTrue($store->confirm($nick->getId(), NickStatus::Pending, $hash, $hash, new DateTimeImmutable('2026-01-01')));
        self::assertSame($hash, $nick->getRescueTokenHash());
        self::assertTrue($nick->isRegistered());
    }

    #[Test]
    public function rejectedOperationsPreserveWholeRowAndRotationSurvivesFlush(): void
    {
        $now = new DateTimeImmutable('2026-01-01');
        $nick = RegisteredNick::createPending('Snapshot', 'old-password', 'snapshot@example.test', 'en', $now->modify('+1 hour'), $now);
        $this->entityManager->persist($nick);
        $this->entityManager->flush();
        $id = $nick->getId();
        $db = $this->entityManager->getConnection();
        $snapshot = static fn (): string => hash('sha256', serialize($db->fetchAllAssociative('SELECT * FROM registered_nicks ORDER BY id')));
        $store = new DoctrineRescueCredentialPersistence($this->entityManager);
        $a = str_repeat('a', 64);
        $b = str_repeat('b', 64);
        $c = str_repeat('c', 64);
        $before = $snapshot();
        self::assertFalse($store->confirm($id, NickStatus::Pending, null, $a, $now->modify('+1 hour')));
        self::assertSame($before, $snapshot());
        self::assertTrue($store->confirm($id, NickStatus::Pending, null, $a, $now));
        $before = $snapshot();
        self::assertFalse($store->rotateAfterRecovery($id, $c, 'bad-password', $b, $now));
        self::assertSame($before, $snapshot());
        self::assertTrue($store->rotateAfterRecovery($id, $a, 'new-password', $b, $now));
        $before = $snapshot();
        self::assertFalse($store->rotateAfterRecovery($id, $a, 'bad-password', $c, $now));
        self::assertSame($before, $snapshot());
        $this->entityManager->flush();
        self::assertSame($before, $snapshot());
        $this->entityManager->clear();
        $loaded = $this->entityManager->find(RegisteredNick::class, $id);
        self::assertNotNull($loaded);
        self::assertSame('new-password', $loaded->getPasswordHash());
        self::assertSame($b, $loaded->getRescueTokenHash());
    }

    #[Test]
    public function emailRecoveryCanPromoteFirstCredentialAndRejectStaleNullExpectation(): void
    {
        $nick = $this->account('Legacy');
        $nick->activate();
        $this->entityManager->flush();
        $store = new DoctrineRescueCredentialPersistence($this->entityManager);
        $now = new DateTimeImmutable('2026-01-01');
        $hash = str_repeat('a', 64);
        self::assertTrue($store->rotateAfterRecovery($nick->getId(), null, 'new-password', $hash, $now));
        self::assertSame($hash, $nick->getRescueTokenHash());
        self::assertSame('new-password', $nick->getPasswordHash());
        self::assertFalse($store->rotateAfterRecovery($nick->getId(), null, 'stale', str_repeat('b', 64), $now));
        self::assertSame($hash, $nick->getRescueTokenHash());
        self::assertSame('new-password', $nick->getPasswordHash());
    }
}
