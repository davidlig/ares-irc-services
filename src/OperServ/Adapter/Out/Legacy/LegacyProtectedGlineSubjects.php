<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Legacy;

use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Application\Port\Out\ProtectedGlineSubjects;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;

use function array_key_exists;
use function strtolower;

final readonly class LegacyProtectedGlineSubjects implements ProtectedGlineSubjects
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
        foreach ($this->operators->findAll() as $operator) {
            $nickname = $this->accounts->findNicknameById($operator->getNickId());
            if (null !== $nickname && !array_key_exists(strtolower($nickname), $nicknames)) {
                $nicknames[strtolower($nickname)] = $nickname;
            }
        }

        return array_values($nicknames);
    }
}
