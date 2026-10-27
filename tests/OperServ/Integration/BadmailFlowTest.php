<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Integration;

use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineBadmailRepository;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\UseCase\Badmail\BadmailAction;
use App\OperServ\Application\UseCase\Badmail\BadmailEmailPolicy;
use App\OperServ\Application\UseCase\Badmail\BadmailEntryView;
use App\OperServ\Application\UseCase\Badmail\BadmailOutcome;
use App\OperServ\Application\UseCase\Badmail\BadmailResult;
use App\OperServ\Application\UseCase\Badmail\ManageBadmail;
use App\OperServ\Application\UseCase\Badmail\ManageBadmailHandler;
use App\OperServ\Domain\Entity\BadmailEntry;
use App\OperServ\Domain\ValueObject\EmailDomain;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function array_column;
use function str_repeat;

#[CoversClass(DoctrineBadmailRepository::class)]
#[CoversClass(ManageBadmailHandler::class)]
#[CoversClass(ManageBadmail::class)]
#[CoversClass(BadmailEmailPolicy::class)]
#[CoversClass(BadmailEntry::class)]
#[CoversClass(EmailDomain::class)]
#[CoversClass(BadmailResult::class)]
#[CoversClass(BadmailEntryView::class)]
final class BadmailFlowTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function managementPreservesPermanentIdsAcrossFilteringPaginationAndDeletion(): void
    {
        $repository = new DoctrineBadmailRepository($this->entityManager);
        $handler = new ManageBadmailHandler($this->createStub(CommandAuditRecorder::class), $repository, 1);
        foreach ([' B.Example.ORG ', 'a.example.org', 'xn--bcher-kva.example'] as $domain) {
            self::assertSame(BadmailOutcome::Added, $handler->handle(new ManageBadmail(BadmailAction::Add, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), domain: $domain, reason: ' Administrative reason '))->outcome);
        }
        $first = $handler->handle(new ManageBadmail(BadmailAction::List, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), pattern: '*'));
        self::assertSame(3, $first->total);
        self::assertSame('b.example.org', $first->entries[0]->domain);
        self::assertSame('Administrative reason', $first->entries[0]->reason);
        $id = $first->entries[0]->id;
        $second = $handler->handle(new ManageBadmail(BadmailAction::List, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), pattern: '*EXAMPLE.ORG', page: 2));
        self::assertSame('a.example.org', $second->entries[0]->domain);
        self::assertGreaterThan($id, $second->entries[0]->id);
        $secondId = $second->entries[0]->id;
        self::assertSame(BadmailOutcome::Deleted, $handler->handle(new ManageBadmail(BadmailAction::Delete, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), id: $id))->outcome);
        self::assertSame($secondId, $handler->handle(new ManageBadmail(BadmailAction::List, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), pattern: 'a.example.org'))->entries[0]->id);
        self::assertSame([], $handler->handle(new ManageBadmail(BadmailAction::List, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), pattern: '*', page: 99))->entries);
        foreach (['%', '_', '?', '[ab]*', '!'] as $literal) {
            self::assertSame(0, $handler->handle(new ManageBadmail(BadmailAction::List, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), pattern: $literal))->total);
        }
        self::assertSame(1, $handler->handle(new ManageBadmail(BadmailAction::List, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), pattern: '*', page: 0))->page);
        self::assertSame(BadmailOutcome::Cleared, $handler->handle(new ManageBadmail(BadmailAction::Clear, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z')))->outcome);
        self::assertSame(0, $handler->handle(new ManageBadmail(BadmailAction::List, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), pattern: '*'))->total);
        $handler->handle(new ManageBadmail(BadmailAction::Add, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), domain: 'later.example', reason: 'new'));
        self::assertGreaterThan($secondId, $handler->handle(new ManageBadmail(BadmailAction::List, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), pattern: '*'))->entries[0]->id);
    }

    #[Test]
    public function rejectedOperationsLeaveSqlStateUnchangedAndDuplicateDoesNotCloseEntityManager(): void
    {
        $repository = new DoctrineBadmailRepository($this->entityManager);
        $handler = new ManageBadmailHandler($this->createStub(CommandAuditRecorder::class), $repository, 0);
        $handler->handle(new ManageBadmail(BadmailAction::Add, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), domain: 'example.org', reason: 'reason'));
        $before = $this->rows();
        $inputs = [
            [new ManageBadmail(BadmailAction::Clear, false, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z')), BadmailOutcome::Denied],
            [new ManageBadmail(BadmailAction::Add, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), domain: 'EXAMPLE.ORG', reason: 'other'), BadmailOutcome::Duplicate],
            [new ManageBadmail(BadmailAction::Add, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), domain: 'example.org.', reason: 'reason'), BadmailOutcome::InvalidDomain],
            [new ManageBadmail(BadmailAction::Add, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), domain: 'other.org', reason: '  '), BadmailOutcome::InvalidReason],
            [new ManageBadmail(BadmailAction::Delete, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), id: 999), BadmailOutcome::NotFound],
            [new ManageBadmail(BadmailAction::Delete, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z')), BadmailOutcome::InvalidId],
            [new ManageBadmail(BadmailAction::Delete, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), id: 0), BadmailOutcome::InvalidId],
            [new ManageBadmail(BadmailAction::List, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), pattern: ''), BadmailOutcome::InvalidPattern],
        ];
        foreach ($inputs as [$input, $outcome]) {
            self::assertSame($outcome, $handler->handle($input)->outcome);
            self::assertSame($before, $this->rows());
        }
        self::assertTrue($this->entityManager->isOpen());
        self::assertSame(BadmailOutcome::Added, $handler->handle(new ManageBadmail(BadmailAction::Add, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), domain: 'later.org', reason: 'reason'))->outcome);
        self::assertSame(1, $handler->handle(new ManageBadmail(BadmailAction::List, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), pattern: '*'))->pageSize);
    }

    #[Test]
    public function policyUsesExactNormalizedDomainAndTreatsOtherAddressesAsUnblocked(): void
    {
        $repository = new DoctrineBadmailRepository($this->entityManager);
        $handler = new ManageBadmailHandler($this->createStub(CommandAuditRecorder::class), $repository, 50);
        $handler->handle(new ManageBadmail(BadmailAction::Add, true, 'Operator', new DateTimeImmutable('2026-10-25T00:00:00Z'), domain: ' EXAMPLE.ORG ', reason: 'reason'));
        $policy = new BadmailEmailPolicy($repository);
        self::assertTrue($policy->isBlocked('User@EXAMPLE.ORG'));
        self::assertTrue($policy->isBlocked('"a@b"@example.org'));
        foreach (['user@sub.example.org', 'user@other.org', 'invalid', 'user@[127.0.0.1]'] as $email) {
            self::assertFalse($policy->isBlocked($email));
        }
        $entry = new BadmailEntry(' Example.ORG ', ' reason ');
        $this->entityManager->persist(new BadmailEntry('persisted.example', 'ORM round trip'));
        $this->entityManager->flush();
        $persisted = $this->entityManager->getRepository(BadmailEntry::class)->findOneBy(['domain' => 'persisted.example']);
        self::assertInstanceOf(BadmailEntry::class, $persisted);
        self::assertGreaterThan(0, $persisted->getId());
        self::assertSame('example.org', $entry->getDomain());
        self::assertSame('reason', $entry->getReason());
    }

    #[Test]
    public function domainRejectsInvalidDnsNames(): void
    {
        foreach (['', 'localhost', '*.example.org', 'user@example.org', 'https://example.org', 'éxample.org', 'example.org.', '-bad.org', 'bad-.org', 'bad_label.org', 'a..org', str_repeat('a', 64) . '.org', str_repeat('a.', 127) . 'org', '127.0.0.1'] as $domain) {
            try {
                new EmailDomain($domain);
                self::fail('Accepted invalid domain: ' . $domain);
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Invalid email domain.', $exception->getMessage());
            }
        }
        self::assertSame('xn--bcher-kva.example', new EmailDomain('XN--BCHER-KVA.example')->value);
    }

    #[Test]
    public function successfulOperationsAuditTheExplicitActorTimeAndReason(): void
    {
        $records = [];
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::exactly(3))->method('record')->willReturnCallback(static function (CommandAuditRecord $record) use (&$records): void { $records[] = $record; });
        $handler = new ManageBadmailHandler($audit, new DoctrineBadmailRepository($this->entityManager), 50);
        $now = new DateTimeImmutable('2026-10-25T00:00:00Z');
        $handler->handle(new ManageBadmail(BadmailAction::Add, true, 'Staff', $now, domain: 'example.org', reason: ' administrative reason '));
        $handler->handle(new ManageBadmail(BadmailAction::Add, true, 'Staff', $now, domain: 'EXAMPLE.ORG', reason: 'duplicate'));
        $id = $this->entityManager->getConnection()->fetchOne('SELECT id FROM operserv_badmail');
        self::assertIsInt($id);
        $handler->handle(new ManageBadmail(BadmailAction::Delete, true, 'Staff', $now, id: $id));
        $handler->handle(new ManageBadmail(BadmailAction::Clear, true, 'Staff', $now));
        self::assertCount(3, $records);
        self::assertSame(['BADMAIL ADD', 'BADMAIL DEL', 'BADMAIL DEL'], array_column($records, 'operation'));
        self::assertSame('administrative reason', $records[0]->reason);
        foreach ($records as $record) {
            self::assertSame('operserv', $record->service);
            self::assertSame('operserv.badmail', $record->permission);
            self::assertSame('Staff', $record->actor);
            self::assertSame($now, $record->occurredAt);
        }
    }

    /** @return list<array<string, mixed>> */
    private function rows(): array
    {
        return $this->entityManager->getConnection()->fetchAllAssociative('SELECT id, domain, reason FROM operserv_badmail ORDER BY id');
    }
}
