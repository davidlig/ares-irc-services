<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\Out\Persistence\Doctrine;

use App\CRegServ\Adapter\Out\Persistence\Doctrine\ChannelRegistrationRequestDoctrineRepository;
use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ChannelRegistrationRequestDoctrineRepository::class)]
#[Group('integration')]
final class ChannelRegistrationRequestDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private ChannelRegistrationRequestRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelRegistrationRequestDoctrineRepository($this->entityManager);
    }

    private function makeRequest(
        string $channel = '#SitioChat',
        int $accountId = 1,
        string $nick = 'JuanJo_Jaen',
        string $description = 'Canal de pruebas',
    ): ChannelRegistrationRequest {
        return ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-14 10:00:00'),
            channelName: $channel,
            requesterAccountId: $accountId,
            requesterNick: $nick,
            description: $description,
        );
    }

    #[Test]
    public function savePersistsRequestAndFindsItByChannel(): void
    {
        $this->repository->save($this->makeRequest());
        $this->flushAndClear();

        $found = $this->repository->findByChannel('#sitiochat');

        self::assertNotNull($found);
        self::assertSame('#SitioChat', $found->getChannel());
        self::assertSame('#sitiochat', $found->getChannelLower());
        self::assertSame('JuanJo_Jaen', $found->getRequesterNick());
        self::assertSame(1, $found->getRequesterAccountId());
        self::assertSame('Canal de pruebas', $found->getDescription());
        self::assertTrue($found->isPending());
    }

    #[Test]
    public function findByChannelReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByChannel('#noexiste'));
    }

    #[Test]
    public function channelLookupIsCaseInsensitiveThroughChannelLower(): void
    {
        $this->repository->save($this->makeRequest(channel: '#MiCanal'));
        $this->flushAndClear();

        self::assertNotNull($this->repository->findByChannel('#micanal'));
        self::assertNotNull($this->repository->findByChannel('#MICANAL'));
    }

    #[Test]
    public function savingTheSameChannelTwiceViolatesTheUniqueConstraint(): void
    {
        $this->repository->save($this->makeRequest());
        $this->flushAndClear();

        $duplicate = $this->makeRequest(channel: '#SITIOCHAT');

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository->save($duplicate);
    }

    #[Test]
    public function findLastRequestByAccountReturnsTheMostRecentOne(): void
    {
        $first = $this->makeRequest(channel: '#Primero');
        $this->repository->save($first);

        $second = ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-14 12:00:00'),
            channelName: '#Segundo',
            requesterAccountId: 1,
            requesterNick: 'JuanJo_Jaen',
        );
        $this->repository->save($second);
        $this->flushAndClear();

        $last = $this->repository->findLastRequestByAccount(1);

        self::assertNotNull($last);
        self::assertSame('#Segundo', $last->getChannel());
    }

    #[Test]
    public function findLastRequestByAccountReturnsNullWithoutRequests(): void
    {
        self::assertNull($this->repository->findLastRequestByAccount(4242));
    }

    #[Test]
    public function findByStatusesFiltersAndReturnsNewestFirst(): void
    {
        $pending = $this->makeRequest(channel: '#Pendiente');
        $this->repository->save($pending);

        $approved = ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-14 08:00:00'),
            channelName: '#Aprobado',
            requesterAccountId: 2,
            requesterNick: 'OtroNick',
        );
        $approved->approve('OperNick', new DateTimeImmutable('2026-10-14 09:00:00'));
        $this->repository->save($approved);
        $this->flushAndClear();

        $pendingOnly = $this->repository->findByStatuses([ChannelRegistrationStatus::Pending]);

        self::assertCount(1, $pendingOnly);
        self::assertSame('#Pendiente', $pendingOnly[0]->getChannel());

        $all = $this->repository->findByStatuses([
            ChannelRegistrationStatus::Pending,
            ChannelRegistrationStatus::Approved,
        ]);

        self::assertCount(2, $all);
        self::assertSame('#Pendiente', $all[0]->getChannel());
        self::assertSame('#Aprobado', $all[1]->getChannel());
    }

    #[Test]
    public function findByStatusesReturnsEmptyArrayWhenNoStatuses(): void
    {
        self::assertSame([], $this->repository->findByStatuses([]));
    }

    #[Test]
    public function findByChannelsIndexesRequestsByChannelLower(): void
    {
        $pending = $this->makeRequest(channel: '#Pendiente');
        $this->repository->save($pending);

        $denied = ChannelRegistrationRequest::request(
            requestedAt: new DateTimeImmutable('2026-10-14 08:00:00'),
            channelName: '#Denegado',
            requesterAccountId: 3,
            requesterNick: 'TercerNick',
        );
        $denied->deny('OperNick', 'Sin descripcion', new DateTimeImmutable('2026-10-14 09:00:00'));
        $this->repository->save($denied);
        $this->flushAndClear();

        $pendingIndex = $this->repository->findByChannels(
            ['#pendiente', '#denegado'],
            [ChannelRegistrationStatus::Pending],
        );

        self::assertArrayHasKey('#pendiente', $pendingIndex);
        self::assertArrayNotHasKey('#denegado', $pendingIndex);

        $allIndex = $this->repository->findByChannels(
            ['#pendiente', '#denegado'],
            [ChannelRegistrationStatus::Pending, ChannelRegistrationStatus::Denied],
        );

        self::assertCount(2, $allIndex);
    }

    #[Test]
    public function approvedRequestKeepsTheAuditTrail(): void
    {
        $request = $this->makeRequest();
        $request->approve('OperNick', new DateTimeImmutable('2026-10-14 11:00:00'));
        $this->repository->save($request);
        $this->flushAndClear();

        $found = $this->repository->findByChannel('#sitiochat');

        self::assertNotNull($found);
        self::assertTrue($found->isApproved());
        self::assertSame('OperNick', $found->getApprovedByNick());
        self::assertNotNull($found->getApprovedAt());
    }

    #[Test]
    public function deniedRequestKeepsReasonAndOper(): void
    {
        $request = $this->makeRequest();
        $request->deny('OperNick', 'Canal de spam', new DateTimeImmutable('2026-10-14 11:00:00'));
        $this->repository->save($request);
        $this->flushAndClear();

        $found = $this->repository->findByChannel('#sitiochat');

        self::assertNotNull($found);
        self::assertTrue($found->isDenied());
        self::assertSame('OperNick', $found->getDeniedByNick());
        self::assertSame('Canal de spam', $found->getDeniedReason());
        self::assertNotNull($found->getDeniedAt());
    }

    #[Test]
    public function deleteRemovesTheRequest(): void
    {
        $request = $this->makeRequest();
        $this->repository->save($request);
        $this->flushAndClear();

        $stored = $this->repository->findByChannel('#sitiochat');
        self::assertNotNull($stored);

        $this->repository->delete($stored);
        $this->flushAndClear();

        self::assertNull($this->repository->findByChannel('#sitiochat'));
    }
}
