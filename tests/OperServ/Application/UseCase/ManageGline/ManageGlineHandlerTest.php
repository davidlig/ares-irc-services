<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\ManageGline;

use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineNetworkActions;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Application\Port\Out\GlineUser;
use App\OperServ\Application\Port\Out\GlineUserLookup;
use App\OperServ\Application\Port\Out\ProtectedGlineSubjects;
use App\OperServ\Application\UseCase\ManageGline\GlineAction;
use App\OperServ\Application\UseCase\ManageGline\ManageGline;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineHandler;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineOutcome;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

use const DATE_ATOM;

#[CoversClass(ManageGlineHandler::class)]
final class ManageGlineHandlerTest extends TestCase
{
    #[Test]
    public function addsResolvedNicknameAndRecordsSemanticAudit(): void
    {
        $repository = new RecordingGlineRepository();
        $network = new RecordingGlineNetworkActions();
        $audit = new RecordingGlineAuditRecorder();
        $handler = $this->handler($repository, $network, $audit, ['BadNick' => new GlineUser('BadNick', 'ident', 'example.test')]);
        $now = new DateTimeImmutable('2026-09-08T10:00:00+00:00');

        $result = $handler->handle(new ManageGline(GlineAction::Add, 'Oper', 44, $now, 'BadNick', '1d', 'abuse'));

        self::assertSame(ManageGlineOutcome::Added, $result->outcome);
        self::assertSame('ident@example.test', $result->mask);
        self::assertSame('ident@example.test', $repository->saved[0][0]);
        self::assertSame(44, $repository->saved[0][1]);
        self::assertSame('abuse', $repository->saved[0][2]);
        self::assertSame('2026-09-09T10:00:00+00:00', $repository->saved[0][3]?->format(DATE_ATOM));
        self::assertSame('ident@example.test', $network->added[0][0]);
        self::assertSame('2026-09-09T10:00:00+00:00', $network->added[0][1]?->format(DATE_ATOM));
        self::assertSame('abuse', $network->added[0][2]);
        self::assertCount(1, $audit->records);
        self::assertSame('GLINE ADD', $audit->records[0]->operation);
        self::assertSame('operserv.gline', $audit->records[0]->permission);
        self::assertSame(['duration' => '1d'], $audit->records[0]->metadata);
    }

    #[Test]
    public function refusesMaskMatchingProtectedUserWithoutSideEffects(): void
    {
        $repository = new RecordingGlineRepository();
        $network = new RecordingGlineNetworkActions();
        $audit = new RecordingGlineAuditRecorder();
        $handler = $this->handler($repository, $network, $audit, ['Root' => new GlineUser('Root', 'rootident', 'host.test')], ['Root']);

        $result = $handler->handle(new ManageGline(GlineAction::Add, 'Oper', 44, new DateTimeImmutable(), 'root*@host.test', '0', 'reason'));

        self::assertSame(ManageGlineOutcome::ProtectedUser, $result->outcome);
        self::assertSame('Root', $result->protectedNickname);
        self::assertSame([], $repository->saved);
        self::assertSame([], $network->added);
        self::assertSame([], $audit->records);
    }

    #[Test]
    public function deletesIndexedEntryAndAuditsIt(): void
    {
        $entry = new GlineEntry('ident@host.test', 4, 'reason', new DateTimeImmutable('2026-09-01'), null);
        $repository = new RecordingGlineRepository(entries: [$entry]);
        $network = new RecordingGlineNetworkActions();
        $audit = new RecordingGlineAuditRecorder();

        $result = $this->handler($repository, $network, $audit)->handle(new ManageGline(GlineAction::Delete, 'Oper', 44, new DateTimeImmutable(), '1'));

        self::assertSame(ManageGlineOutcome::Deleted, $result->outcome);
        self::assertSame([$entry], $repository->removed);
        self::assertSame(['ident@host.test'], $network->removed);
        self::assertSame('GLINE DEL', $audit->records[0]->operation);
        self::assertNull($audit->records[0]->reason);
    }

    #[Test]
    public function listsEntriesWithCreatorNicknames(): void
    {
        $entry = new GlineEntry('*@host.test', 7, null, new DateTimeImmutable('2026-09-01'), null);
        $repository = new RecordingGlineRepository(entries: [$entry]);

        $result = $this->handler($repository, new RecordingGlineNetworkActions(), new RecordingGlineAuditRecorder(), accountNicknames: [7 => 'Creator'])->handle(new ManageGline(GlineAction::List, 'Oper', 44, new DateTimeImmutable()));

        self::assertSame(ManageGlineOutcome::Listed, $result->outcome);
        self::assertSame('Creator', $result->entries[0]->creatorNickname);
        self::assertSame('*@host.test', $result->entries[0]->mask);
    }

    /**
     * @param array<string, GlineUser> $users
     * @param list<string>             $protected
     * @param array<int, string>       $accountNicknames
     */
    private function handler(RecordingGlineRepository $repository, RecordingGlineNetworkActions $network, RecordingGlineAuditRecorder $audit, array $users = [], array $protected = [], array $accountNicknames = []): ManageGlineHandler
    {
        return new ManageGlineHandler($repository, new GlineTestUserLookup($users, $accountNicknames), new GlineTestProtectedSubjects($protected), $network, $audit);
    }
}

final class RecordingGlineRepository implements GlineRepository
{
    /** @var list<GlineEntry> */
    public array $entries;

    /** @var list<array{string, ?int, string, ?DateTimeImmutable}> */
    public array $saved = [];

    /** @var list<GlineEntry> */
    public array $removed = [];

    /** @param list<GlineEntry> $entries */
    public function __construct(array $entries = [])
    {
        $this->entries = $entries;
    }

    public function findByMask(string $mask): ?GlineEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->mask === $mask) {
                return $entry;
            }
        }

        return null;
    }

    public function findAll(): array
    {
        return $this->entries;
    }

    public function findByMaskPattern(string $pattern): array
    {
        return $this->entries;
    }

    public function countAll(): int
    {
        return count($this->entries);
    }

    public function save(string $mask, ?int $creatorAccountId, string $reason, ?DateTimeImmutable $expiresAt): void
    {
        $this->saved[] = [$mask, $creatorAccountId, $reason, $expiresAt];
    }

    public function remove(GlineEntry $entry): void
    {
        $this->removed[] = $entry;
    }
}

final class RecordingGlineNetworkActions implements GlineNetworkActions
{
    /** @var list<array{string, ?DateTimeImmutable, string}> */
    public array $added = [];

    /** @var list<string> */
    public array $removed = [];

    public function add(string $mask, ?DateTimeImmutable $expiresAt, string $reason): void
    {
        $this->added[] = [$mask, $expiresAt, $reason];
    }

    public function remove(string $mask): void
    {
        $this->removed[] = $mask;
    }
}

final readonly class GlineTestUserLookup implements GlineUserLookup
{
    /**
     * @param array<string, GlineUser> $users
     * @param array<int, string>       $accountNicknames
     */
    public function __construct(private array $users, private array $accountNicknames) {}

    public function findByNickname(string $nickname): ?GlineUser
    {
        return $this->users[$nickname] ?? null;
    }

    public function findNicknameByAccountId(int $accountId): ?string
    {
        return $this->accountNicknames[$accountId] ?? null;
    }
}

final readonly class GlineTestProtectedSubjects implements ProtectedGlineSubjects
{
    /** @param list<string> $nicknames */
    public function __construct(private array $nicknames) {}

    public function nicknames(): array
    {
        return $this->nicknames;
    }
}

final class RecordingGlineAuditRecorder implements CommandAuditRecorder
{
    /** @var list<CommandAuditRecord> */
    public array $records = [];

    public function record(CommandAuditRecord $record): void
    {
        $this->records[] = $record;
    }
}
