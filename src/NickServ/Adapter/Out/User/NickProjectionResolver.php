<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\User;

use App\NickServ\Application\Port\In\NickProjection;
use App\NickServ\Application\Port\In\NickProjectionQuery;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\AutomaticModeProfile;
use App\NickServ\Domain\ValueObject\NickStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_map;
use function array_values;
use function is_array;
use function is_numeric;
use function is_string;

final readonly class NickProjectionResolver implements NickProjectionQuery
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nicks,
        private ?EntityManagerInterface $entityManager = null,
    ) {}

    public function all(): array
    {
        if (null !== $this->entityManager) {
            $qb = $this->entityManager->createQueryBuilder();
            $qb->select('n.id, n.nickname, n.passwordHash, n.vhost, n.status, n.reason, n.suspendedUntil, n.automaticModes')
                ->from(RegisteredNick::class, 'n')
                ->orderBy('n.id', 'ASC');

            /** @var list<array<string, mixed>> $rows */
            $rows = $qb->getQuery()->getArrayResult();

            return array_map(self::projectRow(...), $rows);
        }

        return array_values(array_map(self::project(...), $this->nicks->all()));
    }

    public function findById(int $id): ?NickProjection
    {
        $nick = $this->nicks->findById($id);

        return null !== $nick ? self::project($nick) : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function projectRow(array $row): NickProjection
    {
        $id = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
        $nickname = is_string($row['nickname'] ?? null) ? $row['nickname'] : '';
        $passwordHash = is_string($row['passwordHash'] ?? null) ? $row['passwordHash'] : null;
        $vhost = is_string($row['vhost'] ?? null) ? $row['vhost'] : null;

        $status = $row['status'] ?? null;
        if (is_string($status)) {
            $status = NickStatus::tryFrom($status);
        }

        $forbidden = NickStatus::Forbidden === $status;
        $suspended = NickStatus::Suspended === $status;
        $pending = NickStatus::Pending === $status;

        $reason = is_string($row['reason'] ?? null) ? $row['reason'] : null;
        $forbiddenReason = $forbidden ? $reason : null;
        $suspensionReason = $suspended ? $reason : null;

        $suspendedUntil = $row['suspendedUntil'] ?? null;
        if (is_string($suspendedUntil)) {
            $suspendedUntil = new DateTimeImmutable($suspendedUntil);
        } elseif (!$suspendedUntil instanceof DateTimeImmutable) {
            $suspendedUntil = null;
        }
        $suspendedUntil = $suspended ? $suspendedUntil : null;

        /** @var list<string> $rawModes */
        $rawModes = is_array($row['automaticModes'] ?? null) ? array_values($row['automaticModes']) : [];
        $automaticModes = new AutomaticModeProfile($rawModes)->intentions;

        return new NickProjection(
            $id,
            $nickname,
            $passwordHash,
            $vhost,
            $forbidden,
            $forbiddenReason,
            $pending,
            $suspended,
            $suspensionReason,
            $suspendedUntil,
            $automaticModes,
        );
    }

    private static function project(RegisteredNick $nick): NickProjection
    {
        $forbidden = $nick->isForbidden();
        $suspended = $nick->isSuspended();

        return new NickProjection(
            $nick->getId(),
            $nick->getNickname(),
            $nick->getPasswordHash(),
            $nick->getVhost(),
            $forbidden,
            $forbidden ? $nick->getReason() : null,
            $nick->isPending(),
            $suspended,
            $suspended ? $nick->getReason() : null,
            $suspended ? $nick->getSuspendedUntil() : null,
            $nick->getAutomaticModes()->intentions,
        );
    }
}
