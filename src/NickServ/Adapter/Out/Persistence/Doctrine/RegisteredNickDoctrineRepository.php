<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use LogicException;

use function array_filter;
use function array_map;
use function array_unique;
use function array_values;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function max;
use function spl_object_id;
use function sprintf;
use function str_contains;
use function strtolower;
use function strtr;

class RegisteredNickDoctrineRepository implements RegisteredNickRepositoryInterface
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function save(RegisteredNick $nick): void
    {
        $this->em->persist($nick);
        $this->em->flush();
    }

    public function saveQuitBatch(array $nicks): void
    {
        if ([] === $nicks) {
            return;
        }

        $this->em->wrapInTransaction(function () use ($nicks): void {
            $cases = [];
            $ids = [];
            foreach ($nicks as $index => $nick) {
                if (!$this->em->contains($nick) || null === $nick->getLastSeenAt()) {
                    throw new LogicException('Quit activity requires managed accounts with a last-seen timestamp.');
                }
                $ids[] = $nick->getId();
                $cases[] = sprintf('WHEN n.id = :id%d THEN :seen%d', $index, $index);
            }
            $query = $this->em->createQuery(sprintf(
                'UPDATE %s n SET n.lastSeenAt = CASE %s ELSE n.lastSeenAt END WHERE n.id IN (:ids)',
                RegisteredNick::class,
                implode(' ', $cases),
            ));
            $query->setParameter('ids', $ids);
            foreach ($nicks as $index => $nick) {
                $query->setParameter('id' . $index, $nick->getId());
                $query->setParameter('seen' . $index, $nick->getLastSeenAt(), Types::DATETIME_IMMUTABLE);
            }
            $query->execute();
            foreach ($nicks as $nick) {
                // The bulk update bypasses change tracking. Keep the snapshot synchronized
                // so the following transaction flush writes the other quit fields only.
                $this->em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($nick), 'lastSeenAt', $nick->getLastSeenAt());
            }
        });
    }

    public function delete(RegisteredNick $nick): void
    {
        $entity = $this->em->contains($nick) ? $nick : $this->findById($nick->getId());
        if (null !== $entity) {
            $this->em->remove($entity);
            $this->em->flush();
        }
    }

    public function findByNick(string $nickname): ?RegisteredNick
    {
        return $this->em
            ->getRepository(RegisteredNick::class)
            ->findOneBy(['nicknameLower' => strtolower($nickname)]);
    }

    public function findByNicks(array $nicknames): array
    {
        $nicknames = array_values(array_unique(array_map(strtolower(...), $nicknames)));
        if ([] === $nicknames) {
            return [];
        }

        /** @var list<RegisteredNick> $accounts */
        $accounts = $this->em->createQueryBuilder()
            ->select('n')
            ->from(RegisteredNick::class, 'n')
            ->where('n.nicknameLower IN (:nicknames)')
            ->setParameter('nicknames', $nicknames)
            ->getQuery()->getResult();

        return $accounts;
    }

    public function findById(int $id): ?RegisteredNick
    {
        $nick = $this->em->find(RegisteredNick::class, $id);

        return $nick instanceof RegisteredNick ? $nick : null;
    }

    public function findNicknamesByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => 0 < $id)));
        if ([] === $ids) {
            return [];
        }

        $queryBuilder = $this->em->createQueryBuilder();
        $queryBuilder->select('n.id AS accountId, n.nickname AS nickname')
            ->from(RegisteredNick::class, 'n')
            ->where('n.id IN (:ids)')
            ->setParameter('ids', $ids);

        /** @var array<mixed> $result */
        $result = $queryBuilder->getQuery()->getArrayResult();
        $nicknames = [];
        foreach ($result as $row) {
            if (!is_array($row) || !isset($row['accountId'], $row['nickname']) || !is_numeric($row['accountId']) || !is_string($row['nickname'])) {
                continue;
            }

            $nicknames[(int) $row['accountId']] = $row['nickname'];
        }

        return $nicknames;
    }

    public function findByVhost(string $vhost): ?RegisteredNick
    {
        return $this->em
            ->getRepository(RegisteredNick::class)
            ->findOneBy(['vhost' => $vhost]);
    }

    public function findByEmail(string $email): ?RegisteredNick
    {
        $mapping = new ResultSetMappingBuilder($this->em);
        $mapping->addRootEntityFromClassMetadata(RegisteredNick::class, 'n');
        $sql = 'SELECT ' . $mapping->generateSelectClause()
            . ' FROM registered_nicks n WHERE n.email_search_key = LOWER(:email)';
        $sql = $this->em->getConnection()->getDatabasePlatform()->modifyLimitQuery($sql, 1);
        $result = $this->em->createNativeQuery($sql, $mapping)
            ->setParameter('email', $email)
            ->getOneOrNullResult();

        return $result instanceof RegisteredNick ? $result : null;
    }

    public function existsByNick(string $nickname): bool
    {
        return null !== $this->findByNick($nickname);
    }

    public function findRegisteredInactiveSince(DateTimeImmutable $threshold): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('n')
            ->from(RegisteredNick::class, 'n')
            ->where('n.status = :status')
            ->andWhere('n.lastSeenAt < :threshold')
            ->andWhere('n.noExpire = false')
            ->setParameter('status', NickStatus::Registered)
            ->setParameter('threshold', $threshold->format('Y-m-d H:i:s'));

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredNick> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredNick));
    }

    public function deleteExpiredPending(DateTimeImmutable $now): int
    {
        $result = $this->em
            ->createQuery(
                'DELETE FROM App\NickServ\Domain\Entity\RegisteredNick n
                 WHERE n.status = :status
                 AND n.expiresAt IS NOT NULL
                 AND n.expiresAt < :now'
            )
            ->setParameter('status', NickStatus::Pending)
            ->setParameter('now', $now->format('Y-m-d H:i:s'))
            ->execute();

        return is_numeric($result) ? (int) $result : 0;
    }

    public function findByStatus(NickStatus $status): array
    {
        return $this->em
            ->getRepository(RegisteredNick::class)
            ->findBy(['status' => $status]);
    }

    public function findExpiredSuspensions(DateTimeImmutable $now): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('n')
            ->from(RegisteredNick::class, 'n')
            ->where('n.status = :status')
            ->andWhere('n.suspendedUntil IS NOT NULL')
            ->andWhere('n.suspendedUntil < :now')
            ->setParameter('status', NickStatus::Suspended)
            ->setParameter('now', $now->format('Y-m-d H:i:s'));

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredNick> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredNick));
    }

    public function findPendingDeletionBefore(DateTimeImmutable $threshold): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('n')
            ->from(RegisteredNick::class, 'n')
            ->where('n.status = :status')
            ->andWhere('n.pendingDeletionAt IS NOT NULL')
            ->andWhere('n.pendingDeletionAt <= :threshold')
            ->setParameter('status', NickStatus::PendingDeletion)
            ->setParameter('threshold', $threshold->format('Y-m-d H:i:s'));

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredNick> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredNick));
    }

    public function all(): array
    {
        return $this->em->getRepository(RegisteredNick::class)->findAll();
    }

    public function countByPattern(string $pattern): int
    {
        [$predicate, $parameters] = $this->patternPredicate($pattern);

        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(n.id) FROM registered_nicks n WHERE ' . $predicate,
            $parameters,
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function searchByPattern(string $pattern, int $offset, int $limit): array
    {
        [$predicate, $parameters] = $this->patternPredicate($pattern);
        $mapping = new ResultSetMappingBuilder($this->em);
        $mapping->addRootEntityFromClassMetadata(RegisteredNick::class, 'n');
        $sql = 'SELECT ' . $mapping->generateSelectClause()
            . ' FROM registered_nicks n WHERE ' . $predicate
            . ' ORDER BY n.nickname_lower ASC, n.id ASC';
        $sql = $this->em->getConnection()->getDatabasePlatform()->modifyLimitQuery($sql, max(0, $limit), max(0, $offset));

        /** @var array<mixed> $result */
        $result = $this->em->createNativeQuery($sql, $mapping)->setParameters($parameters)->getResult();

        return array_values(array_filter($result, static fn (mixed $row): bool => $row instanceof RegisteredNick));
    }

    public function findNicknamesByLastConnectIp(string $ip): array
    {
        $queryBuilder = $this->em->createQueryBuilder();
        $queryBuilder->select('n.nickname')
            ->from(RegisteredNick::class, 'n')
            ->where('n.lastConnectIp = :ip')
            ->setParameter('ip', $ip)
            ->orderBy('n.nicknameLower', 'ASC')
            ->addOrderBy('n.id', 'ASC');

        /** @var list<string> $nicknames */
        $nicknames = $queryBuilder->getQuery()->getSingleColumnResult();

        return $nicknames;
    }

    /** @return array{string, array<string, string>} */
    private function patternPredicate(string $pattern): array
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();
        $expression = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'n.list_search_key',
            // SQLite LIKE already folds ASCII, just like its built-in LOWER.
            $platform instanceof SQLitePlatform => 'n.nickname_lower',
            default => 'LOWER(n.nickname_lower)',
        };
        $predicate = $expression . " LIKE :pattern ESCAPE '!'";
        $parameters = ['pattern' => self::toLikePattern($pattern)];
        if (!str_contains($pattern, '*')) {
            $equality = $platform instanceof SQLitePlatform ? $expression . ' COLLATE NOCASE' : $expression;
            // Keep LIKE: equality can ignore trailing spaces or broaden linguistic matches.
            $predicate = $equality . ' = :exact AND ' . $predicate;
            $parameters['exact'] = strtolower($pattern);
        }

        return [$predicate, $parameters];
    }

    private static function toLikePattern(string $pattern): string
    {
        return strtr(strtolower($pattern), ['!' => '!!', '%' => '!%', '_' => '!_', '*' => '%']);
    }
}
