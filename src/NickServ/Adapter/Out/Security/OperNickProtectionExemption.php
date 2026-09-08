<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security;

use App\NickServ\Application\Port\Out\NickProtectionExemption;
use App\OperServ\Application\Port\In\ProtectedNickQuery;
use App\Shared\Application\ServiceUidRegistry;

final readonly class OperNickProtectionExemption implements NickProtectionExemption
{
    public function __construct(
        private ProtectedNickQuery $protectedNickQuery,
        private ServiceUidRegistry $serviceUidRegistry,
    ) {}

    public function isRootNickname(string $nickname): bool
    {
        return $this->protectedNickQuery->isRootNickname($nickname);
    }

    public function isServiceNickname(string $nickname): bool
    {
        return null !== $this->serviceUidRegistry->getUidByNickname($nickname);
    }

    public function isIrcopNickId(int $nickId): bool
    {
        return $this->protectedNickQuery->isIrcopNickId($nickId);
    }
}
