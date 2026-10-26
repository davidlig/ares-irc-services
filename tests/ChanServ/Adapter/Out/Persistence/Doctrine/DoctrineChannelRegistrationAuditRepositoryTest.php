<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Adapter\Out\Persistence\Doctrine\DoctrineChannelRegistrationAuditRepository;
use App\ChanServ\Domain\Entity\ChannelRegistrationAudit;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DoctrineChannelRegistrationAuditRepository::class)]
final class DoctrineChannelRegistrationAuditRepositoryTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function persistsDecisionAuditSnapshot(): void
    {
        $decision = ChannelRegistrationAudit::recordDecision(
            '#history',
            17,
            23,
            'ApprovingOper',
            false,
            'Needs a public topic',
            new DateTimeImmutable('2026-10-27T12:00:00+00:00'),
            new DateTimeImmutable('2026-10-26T12:00:00+00:00'),
        );
        new DoctrineChannelRegistrationAuditRepository($this->entityManager)->save($decision);
        $this->flushAndClear();
        $stored = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM channel_registration_audit');

        self::assertNotFalse($stored);
        self::assertSame('#history', $stored['channel_name']);
        self::assertSame(17, $stored['requester_account_id']);
        self::assertSame(23, $stored['operator_account_id']);
        self::assertSame('ApprovingOper', $stored['operator_nickname']);
        self::assertSame(0, $stored['accepted']);
        self::assertSame('Needs a public topic', $stored['rejection_reason']);
        self::assertNotNull($stored['restricted_until']);
        self::assertNotNull($stored['occurred_at']);
    }
}
