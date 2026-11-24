<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\RegisteredChannelBurstSnapshot;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\ChanServ\Domain\ValueObject\ChannelStatus;
use App\Irc\Application\Port\In\NetworkIdentityEquivalence;
use App\Shared\Domain\IrcIdentityFold;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use WeakMap;

use function array_filter;
use function array_key_exists;
use function array_values;
use function count;
use function is_numeric;
use function is_string;
use function max;
use function str_contains;
use function strtr;

final class RegisteredChannelDoctrineRepository implements RegisteredChannelRepositoryInterface, RegisteredChannelBurstSnapshot
{
    private const int PAGE_SIZE = 500;

    /** @var array<string, string>|null stored names keyed by their RFC 1459 key */
    private ?array $burstNames = null;

    /** @var WeakMap<EntityManagerInterface, array<string, RegisteredChannel|null>>|null */
    private static ?\WeakMap $lookupCache = null;

    private static bool $lookupCacheActive = false;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NetworkIdentityEquivalence $equivalence,
    ) {}

    public function beginBurst(): void
    {
        $this->burstNames = null;
        $names = [];
        foreach ($this->em->getConnection()->fetchAllNumeric('SELECT name_lower, name FROM registered_channels') as [$key, $name]) {
            if (!is_string($key) || !is_string($name)) {
                throw new \UnexpectedValueException('Invalid registered channel name in burst snapshot.');
            }
            $names[$key] = $name;
        }
        $this->burstNames = $names;
    }

    public function endBurst(): void
    {
        $this->burstNames = null;
    }

    public function contains(string $channelName): ?bool
    {
        if (null === $this->burstNames) {
            return null;
        }
        $stored = $this->burstNames[IrcIdentityFold::fold($channelName)] ?? null;

        return null !== $stored && $this->equivalence->equivalent($stored, $channelName);
    }

    public function save(RegisteredChannel $channel): void
    {
        $this->invalidateLookup($channel->getNameLower());
        $this->em->persist($channel);
        $this->em->flush();
        if (null !== $this->burstNames) {
            $this->burstNames[IrcIdentityFold::fold($channel->getName())] = $channel->getName();
        }
    }

    public function delete(RegisteredChannel $channel): void
    {
        $this->invalidateLookup($channel->getNameLower());
        $this->em->remove($channel);
        $this->em->flush();
        if (null !== $this->burstNames) {
            unset($this->burstNames[IrcIdentityFold::fold($channel->getName())]);
        }
    }

    public function findByChannelName(string $channelName): ?RegisteredChannel
    {
        $channel = $this->findByChannelNameKey($channelName);

        return null !== $channel && $this->equivalence->equivalent($channel->getName(), $channelName) ? $channel : null;
    }

    public function findByChannelNameKey(string $channelName): ?RegisteredChannel
    {
        $nameLower = IrcIdentityFold::fold($channelName);
        if (null !== $this->burstNames && !isset($this->burstNames[$nameLower])) {
            return null;
        }

        if (!self::$lookupCacheActive) {
            return $this->em
                ->getRepository(RegisteredChannel::class)
                ->findOneBy(['nameLower' => $nameLower]);
        }

        $cache = self::$lookupCache ??= new \WeakMap();
        $byName = $cache[$this->em] ?? [];
        if (array_key_exists($nameLower, $byName)) {
            $cached = $byName[$nameLower];
            if (null === $cached || $this->em->contains($cached)) {
                return $cached;
            }

            unset($byName[$nameLower]);
        }

        $channel = $this->em
            ->getRepository(RegisteredChannel::class)
            ->findOneBy(['nameLower' => $nameLower]);
        $byName[$nameLower] = $channel;
        $cache[$this->em] = $byName;

        return $channel;
    }

    public static function beginLookupCacheLine(): void
    {
        self::resetLookupCache();
        self::$lookupCacheActive = true;
    }

    public static function resetLookupCache(): void
    {
        self::$lookupCache = null;
        self::$lookupCacheActive = false;
    }

    public function countByPattern(string $pattern): int
    {
        [$predicate, $parameters] = $this->listedPatternPredicate($pattern);

        $count = $this->em->getConnection()->fetchOne(
            'SELECT COUNT(c.id) FROM registered_channels c WHERE ' . $predicate,
            $parameters,
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function searchByPattern(string $pattern, int $offset, int $limit): array
    {
        [$predicate, $parameters] = $this->listedPatternPredicate($pattern);
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

    public function existsByChannelName(string $channelName): bool
    {
        return null !== $this->findByChannelNameKey($channelName);
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

    public function countByFounderNickId(int $founderNickId, bool $excludeRejected = false): int
    {
        $query = $this->em->createQueryBuilder()->select('COUNT(c.id)')
            ->from(RegisteredChannel::class, 'c')->where('c.founderNickId = :founder')
            ->setParameter('founder', $founderNickId);
        if ($excludeRejected) {
            $query->andWhere('c.status != :rejected')->setParameter('rejected', ChannelStatus::Rejected);
        }

        return (int) $query->getQuery()->getSingleScalarResult();
    }

    public function findPendingPage(int $offset, int $limit): array
    {
        /** @var list<RegisteredChannel> $result */
        $result = $this->em->createQueryBuilder()->select('c')->from(RegisteredChannel::class, 'c')
            ->where('c.status = :status')->setParameter('status', ChannelStatus::Pending)
            ->orderBy('c.name', 'ASC')->addOrderBy('c.id', 'ASC')
            ->setFirstResult(max(0, $offset))->setMaxResults(max(0, $limit))->getQuery()->getResult();

        return $result;
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

    public function findEstablishedByFounderNickId(int $founderNickId): array
    {
        return $this->findEstablishedByNickRole('founderNickId', $founderNickId);
    }

    public function findEstablishedBySuccessorNickId(int $successorNickId): array
    {
        return $this->findEstablishedByNickRole('successorNickId', $successorNickId);
    }

    public function iterateAll(): iterable
    {
        return $this->iterateMatching(null, []);
    }

    public function iterateRegisteredInactiveSince(\DateTimeImmutable $threshold): iterable
    {
        return $this->iterateMatching(
            'c.status = :activeStatus AND COALESCE(c.lastUsedAt, c.createdAt) < :threshold AND c.noExpire = false',
            ['activeStatus' => ChannelStatus::Active, 'threshold' => $threshold->format('Y-m-d H:i:s')],
        );
    }

    public function iterateExpiredSuspensions(\DateTimeImmutable $now): iterable
    {
        return $this->iterateMatching(
            'c.status = :status AND c.suspendedUntil IS NOT NULL AND c.suspendedUntil <= :now',
            ['status' => ChannelStatus::Suspended, 'now' => $now->format('Y-m-d H:i:s')],
        );
    }

    public function iteratePendingDeletionBefore(\DateTimeImmutable $threshold): iterable
    {
        return $this->iterateMatching(
            'c.status = :status AND c.pendingDeletionAt IS NOT NULL AND c.pendingDeletionAt <= :threshold',
            ['status' => ChannelStatus::PendingDeletion, 'threshold' => $threshold->format('Y-m-d H:i:s')],
        );
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

    private function invalidateLookup(string $nameLower): void
    {
        $cache = self::$lookupCache;
        if (null === $cache || !isset($cache[$this->em])) {
            return;
        }

        $byName = $cache[$this->em];
        unset($byName[$nameLower]);
        $cache[$this->em] = $byName;
    }

    /**
     * @param 'founderNickId'|'successorNickId' $field
     *
     * @return list<RegisteredChannel>
     */
    private function findEstablishedByNickRole(string $field, int $nickId): array
    {
        $statuses = array_filter(
            ChannelStatus::cases(),
            static fn (ChannelStatus $status): bool => ChannelStatus::Pending !== $status && ChannelStatus::Rejected !== $status,
        );

        return $this->em->getRepository(RegisteredChannel::class)->findBy(
            [$field => $nickId, 'status' => $statuses],
            ['name' => 'ASC'],
        );
    }

    /** @return array{string, array<string, string>} */
    private function listedPatternPredicate(string $pattern): array
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
            $parameters['exact'] = IrcIdentityFold::fold($pattern);
        }

        $predicate .= ' AND c.status NOT IN (:registrationPending, :registrationRejected)';
        $parameters['registrationPending'] = ChannelStatus::Pending->value;
        $parameters['registrationRejected'] = ChannelStatus::Rejected->value;

        return [$predicate, $parameters];
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
            if (null !== $lastName) {
                $qb->andWhere('c.name >= :lastName AND (c.name > :lastName OR c.id > :lastId)')
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

    private static function toLikePattern(string $pattern): string
    {
        return strtr(IrcIdentityFold::fold($pattern), ['!' => '!!', '%' => '!%', '_' => '!_', '*' => '%']);
    }
}
