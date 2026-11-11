<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Application\Port\In\NickAccountBatchQuery;
use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Domain\ValueObject\NickStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use UnexpectedValueException;

use function array_map;
use function array_unique;
use function array_values;
use function is_int;
use function is_numeric;
use function is_string;
use function strtolower;

final readonly class NickAccountBatchDoctrineQuery implements NickAccountBatchQuery
{
    public function __construct(private EntityManagerInterface $em) {}

    public function findAccountsByNicks(array $nicknames): array
    {
        $names = array_values(array_unique(array_map(strtolower(...), $nicknames)));
        if ([] === $names) {
            return [];
        }
        $rows = $this->em->getConnection()->executeQuery(
            'SELECT id, nickname, nickname_lower, status, language, timezone, email FROM registered_nicks WHERE nickname_lower IN (?)',
            [$names],
            [ArrayParameterType::STRING],
        )->fetchAllAssociative();
        $accounts = [];
        foreach ($rows as $row) {
            $id = $row['id'];
            $nickname = $row['nickname'];
            $lower = $row['nickname_lower'];
            $status = $row['status'];
            $language = $row['language'];
            $timezone = $row['timezone'];
            $email = $row['email'];
            if ((!is_int($id) && !(is_string($id) && is_numeric($id))) || !is_string($nickname) || !is_string($lower) || !is_string($status) || !is_string($language) || (null !== $timezone && !is_string($timezone)) || (null !== $email && !is_string($email))) {
                throw new UnexpectedValueException('Invalid nickname account data in burst lookup.');
            }
            $nickStatus = NickStatus::tryFrom($status) ?? throw new UnexpectedValueException('Invalid nickname status in burst lookup.');
            $accounts[$lower] = new NickAccountData((int) $id, $nickname, $language, $timezone ?? 'UTC', NickStatus::Registered === $nickStatus, NickStatus::Suspended === $nickStatus, $email);
        }

        return $accounts;
    }
}
