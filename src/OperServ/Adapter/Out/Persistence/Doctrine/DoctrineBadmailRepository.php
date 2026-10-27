<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Persistence\Doctrine;

use App\OperServ\Application\Port\Out\BadmailRepository;
use App\OperServ\Application\UseCase\Badmail\BadmailEntryView;
use App\OperServ\Domain\Entity\BadmailEntry;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use UnexpectedValueException;

use function array_map;
use function is_numeric;
use function is_string;
use function strtolower;
use function strtr;

final readonly class DoctrineBadmailRepository implements BadmailRepository
{
    public function __construct(private EntityManagerInterface $em) {}

    public function add(BadmailEntry $entry): bool
    {
        try {
            // A savepoint also protects an enclosing PostgreSQL transaction on duplicate input.
            // DBAL insertion avoids closing the daemon's EntityManager on uniqueness failure.
            $this->em->getConnection()->transactional(static fn ($connection) => $connection->insert('operserv_badmail', [
                'domain' => $entry->getDomain(),
                'reason' => $entry->getReason(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function delete(int $id): bool
    {
        return 0 < $this->em->getConnection()->delete('operserv_badmail', ['id' => $id]);
    }

    public function clear(): int
    {
        // DELETE, not TRUNCATE: identifiers must not be reset by DEL ALL.
        return (int) $this->em->getConnection()->executeStatement('DELETE FROM operserv_badmail');
    }

    public function contains(string $normalizedDomain): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT id FROM operserv_badmail WHERE domain = ?', [$normalizedDomain]);
    }

    public function countMatching(string $pattern): int
    {
        $count = $this->em->getConnection()->fetchOne("SELECT COUNT(*) FROM operserv_badmail WHERE domain LIKE ? ESCAPE '!'", [self::pattern($pattern)]);
        if (!is_numeric($count)) {
            throw new UnexpectedValueException('Invalid blocked-domain count.');
        }

        return (int) $count;
    }

    public function page(string $pattern, int $offset, int $limit): array
    {
        $connection = $this->em->getConnection();
        $sql = $connection->getDatabasePlatform()->modifyLimitQuery("SELECT id, domain, reason FROM operserv_badmail WHERE domain LIKE ? ESCAPE '!' ORDER BY id ASC", $limit, $offset);

        return array_map(self::view(...), $connection->fetchAllAssociative($sql, [self::pattern($pattern)]));
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): BadmailEntryView
    {
        $id = $row['id'];
        $domain = $row['domain'];
        $reason = $row['reason'];
        if (!is_numeric($id) || !is_string($domain) || !is_string($reason)) {
            throw new UnexpectedValueException('Invalid blocked-domain row.');
        }

        return new BadmailEntryView((int) $id, $domain, $reason);
    }

    private static function pattern(string $pattern): string
    {
        return strtr(strtolower($pattern), ['!' => '!!', '%' => '!%', '_' => '!_', '*' => '%']);
    }
}
