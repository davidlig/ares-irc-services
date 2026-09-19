<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\OperServ\Application\Port\In\OperatorNetworkProjection;
use App\OperServ\Application\Port\In\OperatorNetworkProjectionQuery;
use App\OperServ\Domain\Repository\OperIrcopRepositoryInterface;
use App\OperServ\Domain\ValueObject\ForcedVhost;

final readonly class DoctrineOperatorNetworkProjectionQuery implements OperatorNetworkProjectionQuery
{
    public function __construct(private OperIrcopRepositoryInterface $ircops) {}

    public function findForNick(int $nickId, string $nickname): ?OperatorNetworkProjection
    {
        $ircop = $this->ircops->findByNickId($nickId);
        if (null === $ircop) {
            return null;
        }

        $role = $ircop->getRole();
        $pattern = $role->getForcedVhostPattern();
        $forcedVhost = ForcedVhost::isValidPattern($pattern)
            ? ForcedVhost::fromPattern((string) $pattern)->generateVhost($nickname)
            : null;

        return new OperatorNetworkProjection($nickId, $forcedVhost, $role->getOperclass());
    }

    public function findNickIdsByRoleId(int $roleId): array
    {
        return array_values(array_map(static fn ($ircop): int => $ircop->getNickId(), $this->ircops->findByRoleId($roleId)));
    }
}
