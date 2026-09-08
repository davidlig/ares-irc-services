<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\Kill;

use App\OperServ\Application\Port\Out\KillNetworkUser as KillNetworkUserPort;
use App\OperServ\Application\Port\Out\NetworkUser;
use App\OperServ\Application\Port\Out\NetworkUserLookup;
use App\OperServ\Application\Port\Out\OperatorAccountData;
use App\OperServ\Application\Port\Out\OperatorAccountLookup;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use App\OperServ\Application\UseCase\Kill\KillNetworkUser;
use App\OperServ\Application\UseCase\Kill\KillNetworkUserHandler;
use App\OperServ\Application\UseCase\Kill\KillNetworkUserOutcome;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function in_array;

#[CoversClass(KillNetworkUserHandler::class)]
final class KillNetworkUserHandlerTest extends TestCase
{
    #[Test]
    public function killsOrdinaryUserAndReturnsSafeAuditRecord(): void
    {
        $network = new KillTestNetwork();
        $result = $this->handler(new KillTestUsers(['Target' => $this->target()]), new KillTestRoots(), new KillTestAccounts(), new KillTestRoles(), $network)
            ->handle(new KillNetworkUser('Oper', 'OperServ', 'Target', 'abuse', new DateTimeImmutable('2026-09-08T10:00:00+00:00')));

        self::assertSame(KillNetworkUserOutcome::Killed, $result->outcome);
        self::assertSame([['UID123', 'Killed (OperServ: Oper): abuse']], $network->kills);
        self::assertNotNull($result->auditRecord);
        self::assertSame('KILL', $result->auditRecord->operation);
        self::assertSame('Target', $result->auditRecord->target);
        self::assertSame('ident@host.test', $result->auditRecord->targetHost);
        self::assertSame('encoded-ip', $result->auditRecord->targetIp);
        self::assertSame('abuse', $result->auditRecord->reason);
    }

    #[Test]
    public function refusesMissingRootAndRegisteredIrcOperatorTargets(): void
    {
        $missing = $this->handler(new KillTestUsers(), new KillTestRoots(), new KillTestAccounts(), new KillTestRoles(), new KillTestNetwork())
            ->handle(new KillNetworkUser('Oper', 'OperServ', 'Gone', 'reason', new DateTimeImmutable()));
        self::assertSame(KillNetworkUserOutcome::NotOnline, $missing->outcome);

        $root = $this->handler(new KillTestUsers(['Root' => $this->target('Root')]), new KillTestRoots(['root']), new KillTestAccounts(), new KillTestRoles(), new KillTestNetwork())
            ->handle(new KillNetworkUser('Oper', 'OperServ', 'Root', 'reason', new DateTimeImmutable()));
        self::assertSame(KillNetworkUserOutcome::ProtectedRoot, $root->outcome);

        $operator = $this->target('Ircop', true, true);
        $protected = $this->handler(new KillTestUsers(['Ircop' => $operator]), new KillTestRoots(), new KillTestAccounts(['ircop' => 12]), new KillTestRoles([12]), new KillTestNetwork())
            ->handle(new KillNetworkUser('Oper', 'OperServ', 'Ircop', 'reason', new DateTimeImmutable()));
        self::assertSame(KillNetworkUserOutcome::ProtectedIrcOperator, $protected->outcome);
    }

    #[Test]
    public function reportsUnavailableNetworkWithoutAuditRecord(): void
    {
        $result = $this->handler(new KillTestUsers(['Target' => $this->target()]), new KillTestRoots(), new KillTestAccounts(), new KillTestRoles(), new KillTestNetwork(false))
            ->handle(new KillNetworkUser('Oper', 'OperServ', 'Target', 'reason', new DateTimeImmutable()));

        self::assertSame(KillNetworkUserOutcome::NetworkUnavailable, $result->outcome);
        self::assertNull($result->auditRecord);
    }

    private function handler(NetworkUserLookup $users, RootIdentityRegistry $roots, OperatorAccountLookup $accounts, OperatorRoleAccess $roles, KillNetworkUserPort $network): KillNetworkUserHandler
    {
        return new KillNetworkUserHandler($users, $roots, $accounts, $roles, $network);
    }

    private function target(string $nickname = 'Target', bool $oper = false, bool $identified = false): NetworkUser
    {
        return new NetworkUser('UID123', $nickname, 'ident', 'host.test', 'encoded-ip', $identified, $oper);
    }
}

final readonly class KillTestUsers implements NetworkUserLookup
{
    /** @param array<string, NetworkUser> $users */
    public function __construct(private array $users = []) {}

    public function findByNickname(string $nickname): ?NetworkUser
    {
        return $this->users[$nickname] ?? null;
    }
}
final readonly class KillTestRoots implements RootIdentityRegistry
{
    /** @param list<string> $roots */
    public function __construct(private array $roots = []) {}

    public function contains(string $nickname): bool
    {
        return in_array(strtolower($nickname), $this->roots, true);
    }

    public function allNicknames(): array
    {
        return $this->roots;
    }
}
final readonly class KillTestAccounts implements OperatorAccountLookup
{
    /** @param array<string, int> $ids */
    public function __construct(private array $ids = []) {}

    public function findIdByNickname(string $nickname): ?int
    {
        return $this->ids[$nickname] ?? null;
    }

    public function findNicknameById(int $id): ?string
    {
        return null;
    }

    public function findByNickname(string $nickname): ?OperatorAccountData
    {
        return null;
    }
}
final readonly class KillTestRoles implements OperatorRoleAccess
{
    /** @param list<int> $accounts */
    public function __construct(private array $accounts = []) {}

    public function hasAssignedRole(int $accountId): bool
    {
        return in_array($accountId, $this->accounts, true);
    }

    public function hasPermission(int $accountId, string $permission): bool
    {
        return false;
    }

    public function roleName(int $accountId): ?string
    {
        return null;
    }
}
final class KillTestNetwork implements KillNetworkUserPort
{
    /** @var list<array{string, string}> */
    public array $kills = [];

    public function __construct(private bool $available = true) {}

    public function kill(string $targetUid, string $reason): bool
    {
        if (!$this->available) {
            return false;
        } $this->kills[] = [$targetUid, $reason];

        return true;
    }
}
