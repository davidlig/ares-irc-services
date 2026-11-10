<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Application\Port\Out\ProtectedGlineSubjects;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use App\OperServ\Domain\Entity\OperIrcop;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;

use function array_key_exists;
use function array_map;
use function array_unique;
use function array_values;
use function strtolower;

final readonly class DoctrineProtectedGlineSubjects implements ProtectedGlineSubjects
{
    public function __construct(
        private RootIdentityRegistry $roots,
        private OperIrcopRepositoryInterface $operators,
        private NickAccountQuery $accounts,
    ) {}

    public function nicknames(): array
    {
        $nicknames = [];
        foreach ($this->roots->allNicknames() as $nickname) {
            $nicknames[strtolower($nickname)] = $nickname;
        }
        $operators = $this->operators->findAll();
        $nickIds = array_values(array_unique(array_map(
            static fn (OperIrcop $operator): int => $operator->getNickId(),
            $operators,
        )));
        $nicknamesById = [] === $nickIds ? [] : $this->accounts->findNicknamesByIds($nickIds);
        foreach ($operators as $operator) {
            $nickname = $nicknamesById[$operator->getNickId()] ?? null;
            if (null !== $nickname && !array_key_exists(strtolower($nickname), $nicknames)) {
                $nicknames[strtolower($nickname)] = $nickname;
            }
        }

        return array_values($nicknames);
    }
}
