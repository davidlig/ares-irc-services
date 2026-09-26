<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use DateTimeImmutable;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;

use function array_filter;
use function array_values;
use function count;
use function strtolower;
use function strtr;

final readonly class RegisteredChannelDoctrineRepository implements RegisteredChannelRepositoryInterface
{
    private const int PAGE_SIZE = 500;

    public function __construct(private EntityManagerInterface $em) {}

    public function save(RegisteredChannel $channel): void
    {
        $this->em->persist($channel);
        $this->em->flush();
    }

    public function delete(RegisteredChannel $channel): void
    {
        $this->em->remove($channel);
        $this->em->flush();
    }

    public function findByChannelName(string $channelName): ?RegisteredChannel
    {
        $nameLower = strtolower($channelName);

        return $this->em
            ->getRepository(RegisteredChannel::class)
            ->findOneBy(['nameLower' => $nameLower]);
    }

    public function countByPattern(string $pattern): int
    {
        [$predicate, $parameters] = $this->patternPredicate($pattern);

        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(c.id) FROM registered_channels c WHERE ' . $predicate,
            $parameters,
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function searchByPattern(string $pattern, int $offset, int $limit): array
    {
        [$predicate, $parameters] = $this->patternPredicate($pattern);
        $mapping = new ResultSetMappingBuilder($this->em);
        $mapping->addRootEntityFromClassMetadata(RegisteredChannel::class, 'c');
        $sql = 'SELECT ' . $mapping->generateSelectClause()
            . ' FROM registered_channels c WHERE ' . $predicate
            . ' ORDER BY c.name_lower ASC, c.id ASC';
        $sql = $this->em->getConnection()->getDatabasePlatform()->modifyLimitQuery($sql, max(0, $limit), max(0, $offset));

        /** @var array<mixed> $result */
        $result = $this->em->createNativeQuery($sql, $mapping)->setParameters($parameters)->getResult();

        return array_values(array_filter($result, static fn (mixed $row): bool => $row instanceof RegisteredChannel));
    }

    /** @return array{string, array<string, string>} */
    private function patternPredicate(string $pattern): array
    {
        $platform = $this->em->getConnection()->getDatabasePlatform();
        $expression = match (true) {
            $platform instanceof AbstractMySQLPlatform => 'c.list_search_key',
            // SQLite LIKE already folds ASCII, just like its built-in LOWER.
            $platform instanceof SQLitePlatform => 'c.name_lower',
            default => 'LOWER(c.name_lower)',
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

    public function existsByChannelName(string $channelName): bool
    {
        return null !== $this->findByChannelName($channelName);
    }

    /**
     * @return RegisteredChannel[]
     */
    public function findByFounderNickId(int $founderNickId): array
    {
        $result = $this->em
            ->getRepository(RegisteredChannel::class)
            ->findBy(['founderNickId' => $founderNickId], ['name' => 'ASC']);

        // @phpstan-ignore instanceof.alwaysTrue
        return array_filter($result, static fn ($row): bool => $row instanceof RegisteredChannel);
    }

    /**
     * @return RegisteredChannel[]
     */
    public function findBySuccessorNickId(int $successorNickId): array
    {
        $result = $this->em
            ->getRepository(RegisteredChannel::class)
            ->findBy(['successorNickId' => $successorNickId], ['name' => 'ASC']);

        // @phpstan-ignore instanceof.alwaysTrue
        return array_filter($result, static fn ($row): bool => $row instanceof RegisteredChannel);
    }

    public function iterateAll(): iterable
    {
        return $this->iterateMatching(null, []);
    }

    public function iterateRegisteredInactiveSince(DateTimeImmutable $threshold): iterable
    {
        return $this->iterateMatching(
            'COALESCE(c.lastUsedAt, c.createdAt) < :threshold AND c.noExpire = false',
            ['threshold' => $threshold->format('Y-m-d H:i:s')],
        );
    }

    public function iterateExpiredSuspensions(DateTimeImmutable $now): iterable
    {
        return $this->iterateMatching(
            'c.status = :status AND c.suspendedUntil IS NOT NULL AND c.suspendedUntil <= :now',
            ['status' => ChannelStatus::Suspended, 'now' => $now->format('Y-m-d H:i:s')],
        );
    }

    public function iteratePendingDeletionBefore(DateTimeImmutable $threshold): iterable
    {
        return $this->iterateMatching(
            'c.status = :status AND c.pendingDeletionAt IS NOT NULL AND c.pendingDeletionAt <= :threshold',
            ['status' => ChannelStatus::PendingDeletion, 'threshold' => $threshold->format('Y-m-d H:i:s')],
        );
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return iterable<RegisteredChannel>
     */
    private function iterateMatching(?string $filter, array $parameters): iterable
    {
        $lastName = null;
        $lastId = null;

        do {
            $qb = $this->em->createQueryBuilder()
                ->select('c')
                ->from(RegisteredChannel::class, 'c')
                ->orderBy('c.name', 'ASC')
                ->addOrderBy('c.id', 'ASC')
                ->setMaxResults(self::PAGE_SIZE);

            if (null !== $filter) {
                $qb->andWhere($filter);
            }
            if (null !== $lastName && null !== $lastId) {
                $qb->andWhere('(c.name > :lastName OR (c.name = :lastName AND c.id > :lastId))')
                    ->setParameter('lastName', $lastName)
                    ->setParameter('lastId', $lastId);
            }
            foreach ($parameters as $name => $value) {
                $qb->setParameter($name, $value);
            }

            /** @var array<RegisteredChannel> $page */
            $page = $qb->getQuery()->getResult();
            foreach ($page as $channel) {
                $lastName = $channel->getName();
                $lastId = $channel->getId();
                yield $channel;
                if ($this->em->contains($channel)) {
                    $this->em->detach($channel);
                }
            }
        } while (self::PAGE_SIZE === count($page));
    }

    public function findByIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        return $this->em
            ->getRepository(RegisteredChannel::class)
            ->findBy(['id' => $ids]);
    }

    public function clearSuccessorNickId(int $successorNickId): void
    {
        $this->em
            ->createQuery(
                'UPDATE ' . RegisteredChannel::class . ' c SET c.successorNickId = NULL WHERE c.successorNickId = :successorNickId'
            )
            ->setParameter('successorNickId', $successorNickId)
            ->execute();
    }

    public function findForbiddenChannels(): array
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('c')
            ->from(RegisteredChannel::class, 'c')
            ->where('c.status = :status')
            ->setParameter('status', ChannelStatus::Forbidden);

        /** @var array<mixed> $result */
        $result = $qb->getQuery()->getResult();

        /* @var array<RegisteredChannel> */
        return array_values(array_filter($result, static fn ($row): bool => $row instanceof RegisteredChannel));
    }

    private static function toLikePattern(string $pattern): string
    {
        return strtr(strtolower($pattern), ['!' => '!!', '%' => '!%', '_' => '!_', '*' => '%']);
    }
}
