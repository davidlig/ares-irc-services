<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\UseCase\ManageGline;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineNetworkActions;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Application\Port\Out\GlineUser;
use App\OperServ\Application\Port\Out\GlineUserLookup;
use App\OperServ\Application\Port\Out\ProtectedGlineSubjects;
use App\OperServ\Application\UseCase\ManageGline\GlineAction;
use App\OperServ\Application\UseCase\ManageGline\GlineListEntry;
use App\OperServ\Application\UseCase\ManageGline\ManageGline;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineHandler;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineOutcome;
use App\OperServ\Application\UseCase\ManageGline\ManageGlineResult;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

use function count;

use const DATE_ATOM;

#[CoversClass(ManageGlineHandler::class)]
#[CoversClass(ManageGline::class)]
#[CoversClass(ManageGlineResult::class)]
#[CoversClass(GlineListEntry::class)]
#[CoversClass(GlineEntry::class)]
#[CoversClass(GlineUser::class)]
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
        self::assertSame(CommandAuditCategory::OperatorAction, $audit->records[0]->category);
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

    /** @param array<string, GlineUser> $users */
    #[Test]
    #[DataProvider('rejectedAdds')]
    public function rejectsInvalidOrUnsafeAdds(ManageGline $command, array $users, ManageGlineOutcome $expected): void
    {
        $repository = new RecordingGlineRepository();
        $network = new RecordingGlineNetworkActions();
        $audit = new RecordingGlineAuditRecorder();

        $result = $this->handler($repository, $network, $audit, $users)->handle($command);

        self::assertSame($expected, $result->outcome);
        self::assertSame([], $repository->saved);
        self::assertSame([], $network->added);
        self::assertSame([], $audit->records);
    }

    /** @return iterable<string, array{ManageGline, array<string, GlineUser>, ManageGlineOutcome}> */
    public static function rejectedAdds(): iterable
    {
        $now = new DateTimeImmutable('2026-09-08T10:00:00+00:00');

        yield 'missing required arguments' => [new ManageGline(GlineAction::Add, 'Oper', 1, $now), [], ManageGlineOutcome::InvalidRequest];
        yield 'invalid mask with bang' => [new ManageGline(GlineAction::Add, 'Oper', 1, $now, 'nick!ident@host', '1h', 'reason'), [], ManageGlineOutcome::InvalidMask];
        yield 'nickname not connected' => [new ManageGline(GlineAction::Add, 'Oper', 1, $now, 'Missing', '1h', 'reason'), [], ManageGlineOutcome::UserNotFound];
        yield 'resolved global mask' => [new ManageGline(GlineAction::Add, 'Oper', 1, $now, 'Any', '1h', 'reason'), ['Any' => new GlineUser('Any', '*', '*')], ManageGlineOutcome::GlobalMask];
        yield 'dangerously broad mask' => [new ManageGline(GlineAction::Add, 'Oper', 1, $now, '*@a.c', '1h', 'reason'), [], ManageGlineOutcome::DangerousMask];
        yield 'invalid expiry' => [new ManageGline(GlineAction::Add, 'Oper', 1, $now, 'ident@host.test', 'invalid', 'reason'), [], ManageGlineOutcome::InvalidExpiry];
    }

    #[Test]
    public function rejectsExistingActiveEntryButReplacesExpiredEntry(): void
    {
        $now = new DateTimeImmutable('2026-09-08T10:00:00+00:00');
        $active = new GlineEntry('active@host.test', null, null, $now, new DateTimeImmutable('2026-09-09'));
        $activeRepository = new RecordingGlineRepository([$active]);

        self::assertSame(
            ManageGlineOutcome::AlreadyExists,
            $this->handler($activeRepository, new RecordingGlineNetworkActions(), new RecordingGlineAuditRecorder())
                ->handle(new ManageGline(GlineAction::Add, 'Oper', 1, $now, 'active@host.test', '1h', 'reason'))->outcome,
        );

        $expired = new GlineEntry('expired@host.test', null, null, $now, new DateTimeImmutable('2026-09-07'));
        $expiredRepository = new RecordingGlineRepository([$expired]);
        $result = $this->handler($expiredRepository, new RecordingGlineNetworkActions(), new RecordingGlineAuditRecorder())
            ->handle(new ManageGline(GlineAction::Add, 'Oper', 1, $now, 'expired@host.test', '0', 'reason'));

        self::assertSame(ManageGlineOutcome::Added, $result->outcome);
        self::assertSame([$expired], $expiredRepository->removed);
    }

    #[Test]
    public function enforcesConfiguredLimitAfterDiscardingNoEntry(): void
    {
        $repository = new RecordingGlineRepository([
            new GlineEntry('existing@host.test', null, null, new DateTimeImmutable(), null),
        ]);
        $handler = new ManageGlineHandler(
            $repository,
            new GlineTestUserLookup([], []),
            new GlineTestProtectedSubjects([]),
            new RecordingGlineNetworkActions(),
            new RecordingGlineAuditRecorder(),
            1,
        );

        $result = $handler->handle(new ManageGline(GlineAction::Add, 'Oper', 1, new DateTimeImmutable(), 'new@host.test', '0', 'reason'));

        self::assertSame(ManageGlineOutcome::LimitReached, $result->outcome);
        self::assertSame(1, $result->limit);
    }

    #[Test]
    public function handlesInvalidAndMissingDeleteSelectors(): void
    {
        $handler = $this->handler(new RecordingGlineRepository(), new RecordingGlineNetworkActions(), new RecordingGlineAuditRecorder());
        $now = new DateTimeImmutable();

        self::assertSame(ManageGlineOutcome::InvalidRequest, $handler->handle(new ManageGline(GlineAction::Delete, 'Oper', 1, $now))->outcome);
        self::assertSame(ManageGlineOutcome::NotFound, $handler->handle(new ManageGline(GlineAction::Delete, 'Oper', 1, $now, 'missing@host.test'))->outcome);
        self::assertSame(ManageGlineOutcome::NotFound, $handler->handle(new ManageGline(GlineAction::Delete, 'Oper', 1, $now, '0'))->outcome);
    }

    #[Test]
    public function returnsEmptyForUnmatchedListPattern(): void
    {
        $repository = new RecordingGlineRepository();

        $result = $this->handler($repository, new RecordingGlineNetworkActions(), new RecordingGlineAuditRecorder())
            ->handle(new ManageGline(GlineAction::List, 'Oper', 1, new DateTimeImmutable(), listPattern: '*@missing'));

        self::assertSame(ManageGlineOutcome::ListEmpty, $result->outcome);
    }

    #[Test]
    public function reportsUnknownAction(): void
    {
        $result = $this->handler(new RecordingGlineRepository(), new RecordingGlineNetworkActions(), new RecordingGlineAuditRecorder())
            ->handle(new ManageGline(GlineAction::Unknown, 'Oper', 1, new DateTimeImmutable()));

        self::assertSame(ManageGlineOutcome::UnknownAction, $result->outcome);
    }

    #[Test]
    public function defensiveMatcherRejectsMaskWithoutSeparator(): void
    {
        $handler = $this->handler(new RecordingGlineRepository(), new RecordingGlineNetworkActions(), new RecordingGlineAuditRecorder());
        $method = new ReflectionMethod($handler, 'matches');

        self::assertFalse($method->invoke($handler, 'nickname', new GlineUser('Nick', 'ident', 'host.test')));
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
