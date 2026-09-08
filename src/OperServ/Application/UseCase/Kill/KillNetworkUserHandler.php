<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Kill;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\Out\KillNetworkUser as KillNetworkUserPort;
use App\OperServ\Application\Port\Out\NetworkUserLookup;
use App\OperServ\Application\Port\Out\OperatorAccountLookup;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;

use function sprintf;
use function strtolower;

/** Orchestrates KILL without knowing IRC command syntax or presentation. */
final readonly class KillNetworkUserHandler
{
    public function __construct(
        private NetworkUserLookup $users,
        private RootIdentityRegistry $roots,
        private OperatorAccountLookup $nickAccounts,
        private OperatorRoleAccess $operatorRoles,
        private KillNetworkUserPort $network,
    ) {}

    public function handle(KillNetworkUser $command): KillNetworkUserResult
    {
        $target = $this->users->findByNickname($command->targetNickname);
        if (null === $target) {
            return KillNetworkUserResult::notOnline($command->targetNickname);
        }

        if ($this->roots->contains($target->nickname)) {
            return KillNetworkUserResult::protectedRoot($command->targetNickname);
        }

        if ($this->isProtectedIrcOperator($target->nickname, $target->ircOperator, $target->identified)) {
            return KillNetworkUserResult::protectedIrcOperator($command->targetNickname);
        }

        $killReason = sprintf('Killed (%s: %s): %s', $command->serviceNickname, $command->actorNickname, $command->reason);
        if (!$this->network->kill($target->uid, $killReason)) {
            return KillNetworkUserResult::networkUnavailable($command->targetNickname);
        }

        return KillNetworkUserResult::killed(
            $command->targetNickname,
            $command->reason,
            new CommandAuditRecord(
                category: CommandAuditCategory::OperatorAction,
                service: 'OperServ',
                actor: $command->actorNickname,
                operation: 'KILL',
                occurredAt: $command->occurredAt,
                target: $command->targetNickname,
                reason: $command->reason,
                permission: 'operserv.kill',
                targetHost: $target->ident . '@' . $target->hostname,
                targetIp: $target->ipBase64,
            ),
        );
    }

    private function isProtectedIrcOperator(string $nickname, bool $isOper, bool $isIdentified): bool
    {
        if (!$isOper || !$isIdentified) {
            return false;
        }

        $accountId = $this->nickAccounts->findIdByNickname(strtolower($nickname));

        return null !== $accountId && $this->operatorRoles->hasAssignedRole($accountId);
    }
}
