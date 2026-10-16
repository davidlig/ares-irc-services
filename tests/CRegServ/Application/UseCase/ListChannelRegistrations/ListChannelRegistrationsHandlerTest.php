<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Application\UseCase\ListChannelRegistrations;

use App\CRegServ\Adapter\Out\Persistence\Doctrine\ChannelRegistrationRequestDoctrineRepository;
use App\CRegServ\Application\UseCase\ListChannelRegistrations\ChannelRegistrationRow;
use App\CRegServ\Application\UseCase\ListChannelRegistrations\ListChannelRegistrations;
use App\CRegServ\Application\UseCase\ListChannelRegistrations\ListChannelRegistrationsHandler;
use App\CRegServ\Application\UseCase\ListChannelRegistrations\ListChannelRegistrationsResult;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mirrors pulsar's do_pendientes and do_denegados (modules/cregserv/main.c:534-575): the
 * oper work queue is a plain read of the requests waiting for review, and the denied list is
 * the same read over the other status, each one showing only the fields the oper needs.
 */
#[CoversClass(ListChannelRegistrationsHandler::class)]
#[CoversClass(ListChannelRegistrations::class)]
#[CoversClass(ListChannelRegistrationsResult::class)]
#[CoversClass(ChannelRegistrationRow::class)]
#[Group('integration')]
final class ListChannelRegistrationsHandlerTest extends DoctrineIntegrationTestCase
{
    private ChannelRegistrationRequestDoctrineRepository $repository;

    private ListChannelRegistrationsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new ChannelRegistrationRequestDoctrineRepository($this->entityManager);
        $this->handler = new ListChannelRegistrationsHandler($this->repository);
    }

    private function queue(
        string $channelName = '#SitioChat',
        string $requesterNick = 'JuanJo_Jaen',
        string $description = 'Canal de pruebas',
        DateTimeImmutable $requestedAt = new DateTimeImmutable('2026-10-15 10:00:00'),
    ): ChannelRegistrationRequest {
        $request = ChannelRegistrationRequest::request(
            requestedAt: $requestedAt,
            channelName: $channelName,
            requesterAccountId: 7,
            requesterNick: $requesterNick,
            description: $description,
        );
        $this->repository->save($request);
        $this->flushAndClear();

        return $request;
    }

    #[Test]
    public function listsThePendingQueueNewestFirst(): void
    {
        $this->queue(channelName: '#Viejo', requestedAt: new DateTimeImmutable('2026-10-15 08:00:00'));
        $this->queue(channelName: '#Nuevo', requesterNick: 'OtraCuenta', requestedAt: new DateTimeImmutable('2026-10-15 09:00:00'));

        $result = $this->handler->handle(new ListChannelRegistrations(ChannelRegistrationStatus::Pending));

        self::assertCount(2, $result->rows);
        self::assertSame('#Nuevo', $result->rows[0]->channel);
        self::assertSame('OtraCuenta', $result->rows[0]->requesterNick);
        self::assertSame('#Viejo', $result->rows[1]->channel);
        self::assertSame('JuanJo_Jaen', $result->rows[1]->requesterNick);
    }

    #[Test]
    public function carriesTheFieldsTheOperReadsInThePendingLine(): void
    {
        $this->queue(
            channelName: '#SitioChat',
            description: 'Canal de la comunidad',
            requestedAt: new DateTimeImmutable('2026-10-15 10:00:00'),
        );

        $row = $this->handler->handle(new ListChannelRegistrations(ChannelRegistrationStatus::Pending))->rows[0];

        self::assertSame('#SitioChat', $row->channel);
        self::assertSame('JuanJo_Jaen', $row->requesterNick);
        self::assertSame('Canal de la comunidad', $row->description);
        self::assertSame('2026-10-15 10:00:00', $row->createdAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function keepsTheTwoWorkQueuesApart(): void
    {
        $this->queue(channelName: '#Pendiente');
        $this->queue(channelName: '#Denegado');
        $denied = $this->repository->findByChannel('#denegado');
        self::assertNotNull($denied);
        $denied->deny('NetAdmin', 'Spam', new DateTimeImmutable('2026-10-15 11:00:00'));
        $this->flushAndClear();

        $pending = $this->handler->handle(new ListChannelRegistrations(ChannelRegistrationStatus::Pending));
        $deniedList = $this->handler->handle(new ListChannelRegistrations(ChannelRegistrationStatus::Denied));

        self::assertCount(1, $pending->rows, 'A denied channel must not show up as pending.');
        self::assertSame('#Pendiente', $pending->rows[0]->channel);

        self::assertCount(1, $deniedList->rows);
        self::assertSame('#Denegado', $deniedList->rows[0]->channel);
    }

    #[Test]
    public function carriesWhoDeniedItWhenAndWhy(): void
    {
        $this->queue(channelName: '#Denegado', description: 'Canal de la comunidad');
        $denied = $this->repository->findByChannel('#denegado');
        self::assertNotNull($denied);
        $denied->deny('NetAdmin', 'No cumple las normas', new DateTimeImmutable('2026-10-15 11:00:00'));
        $this->flushAndClear();

        $row = $this->handler->handle(new ListChannelRegistrations(ChannelRegistrationStatus::Denied))->rows[0];

        self::assertSame('No cumple las normas', $row->deniedReason);
        self::assertSame('2026-10-15 11:00:00', $row->deniedAt?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function returnsAnEmptyListWhenNothingWasEverAsked(): void
    {
        $result = $this->handler->handle(new ListChannelRegistrations(ChannelRegistrationStatus::Pending));

        self::assertSame([], $result->rows);
    }

    #[Test]
    public function doesNotExposeAPendingRowDenialFields(): void
    {
        $this->queue(channelName: '#Pendiente');

        $row = $this->handler->handle(new ListChannelRegistrations(ChannelRegistrationStatus::Pending))->rows[0];

        self::assertNull($row->deniedReason);
        self::assertNull($row->deniedAt);
    }
}
