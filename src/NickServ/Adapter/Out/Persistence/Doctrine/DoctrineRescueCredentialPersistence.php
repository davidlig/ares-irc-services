<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Application\Port\Out\RescueCredentialPersistence;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

final readonly class DoctrineRescueCredentialPersistence implements RescueCredentialPersistence
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function confirm(int $accountId, NickStatus $expectedStatus, ?string $expectedHash, string $newHash, DateTimeImmutable $now): bool
    {
        if (NickStatus::Pending !== $expectedStatus && NickStatus::Registered !== $expectedStatus) {
            return false;
        }
        $this->assertHash($newHash);
        $params = ['id' => $accountId, 'status' => $expectedStatus->value, 'hash' => $newHash];
        $hashCondition = 'token IS NULL';
        if (null !== $expectedHash) {
            $params['expected'] = $expectedHash;
            $hashCondition = 'token = :expected';
        }
        $set = 'token = :hash';
        $expiryCondition = '';
        $types = [];
        if (NickStatus::Pending === $expectedStatus) {
            $set .= ", status = 'registered', expires_at = NULL";
            $expiryCondition = ' AND expires_at IS NOT NULL AND expires_at > :now';
            $params['now'] = $now;
            $types['now'] = Types::DATETIME_IMMUTABLE;
        }

        return $this->entityManager->getConnection()->transactional(function () use ($accountId, $set, $hashCondition, $expiryCondition, $params, $types): bool {
            $affected = $this->entityManager->getConnection()->executeStatement(
                'UPDATE registered_nicks SET ' . $set . ' WHERE id = :id AND status = :status AND ' . $hashCondition . $expiryCondition,
                $params,
                $types,
            );
            if (1 !== $affected) {
                return false;
            }
            $this->refresh($accountId);

            return true;
        });
    }

    public function rotateAfterRecovery(int $accountId, string $expectedHash, string $newPasswordHash, string $newTokenHash, DateTimeImmutable $now): bool
    {
        $this->assertHash($newTokenHash);

        return $this->entityManager->getConnection()->transactional(function () use ($accountId, $expectedHash, $newPasswordHash, $newTokenHash): bool {
            $affected = $this->entityManager->getConnection()->executeStatement(
                "UPDATE registered_nicks SET password_hash = :password, token = :hash WHERE id = :id AND status = 'registered' AND token = :expected",
                ['password' => $newPasswordHash, 'hash' => $newTokenHash, 'id' => $accountId, 'expected' => $expectedHash],
            );
            if (1 !== $affected) {
                return false;
            }
            $this->refresh($accountId);

            return true;
        });
    }

    public function replacePending(int $accountId, ?string $expectedHash, string $newHash, DateTimeImmutable $now): bool
    {
        $this->assertHash($newHash);
        $params = ['id' => $accountId, 'hash' => $newHash, 'now' => $now];
        $condition = 'token IS NULL';
        if (null !== $expectedHash) {
            $condition = 'token = :expected';
            $params['expected'] = $expectedHash;
        }

        return $this->entityManager->getConnection()->transactional(function () use ($accountId, $params, $condition): bool {
            $affected = $this->entityManager->getConnection()->executeStatement(
                "UPDATE registered_nicks SET token = :hash WHERE id = :id AND status = 'pending' AND expires_at > :now AND " . $condition,
                $params,
                ['now' => Types::DATETIME_IMMUTABLE],
            );
            if (1 !== $affected) {
                return false;
            }
            $this->refresh($accountId);

            return true;
        });
    }

    private function refresh(int $accountId): void
    {
        $account = $this->entityManager->find(RegisteredNick::class, $accountId);
        if (null !== $account) {
            $this->entityManager->refresh($account);
        }
    }

    private function assertHash(string $hash): void
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/D', $hash)) {
            throw new InvalidArgumentException('Rescue credential must be a lowercase SHA-256 hash.');
        }
    }
}
