<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Domain\Entity;

use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(ChannelRegistrationRequest::class)]
final class ChannelRegistrationRequestTest extends TestCase
{
    #[Test]
    public function requestCreatesPendingRequestWithNormalizedChannel(): void
    {
        $requestedAt = new DateTimeImmutable('2026-01-02 03:04:05');
        $request = ChannelRegistrationRequest::request(
            $requestedAt,
            '#CanalEjemplo',
            42,
            'Solicitante',
            'Canal de pruebas',
        );

        self::assertSame('#CanalEjemplo', $request->getChannel());
        self::assertSame('#canalejemplo', $request->getChannelLower());
        self::assertSame(42, $request->getRequesterAccountId());
        self::assertSame('Solicitante', $request->getRequesterNick());
        self::assertSame('Canal de pruebas', $request->getDescription());
        self::assertSame($requestedAt, $request->getCreatedAt());
        self::assertSame(ChannelRegistrationStatus::Pending, $request->getStatus());
        self::assertTrue($request->isPending());
        self::assertFalse($request->isApproved());
        self::assertFalse($request->isDenied());
        self::assertNull($request->getApprovedAt());
        self::assertNull($request->getApprovedByNick());
        self::assertNull($request->getDeniedAt());
        self::assertNull($request->getDeniedByNick());
        self::assertNull($request->getDeniedReason());
    }

    #[Test]
    public function requestDefaultsDescriptionToEmptyString(): void
    {
        $request = ChannelRegistrationRequest::request(
            new DateTimeImmutable('2026-01-02 03:04:05'),
            '#canal',
            1,
            'Nick',
        );

        self::assertSame('', $request->getDescription());
    }

    #[Test]
    public function getIdReturnsPersistedIdentifier(): void
    {
        $request = ChannelRegistrationRequest::request(
            new DateTimeImmutable('2026-01-02 03:04:05'),
            '#canal',
            1,
            'Nick',
        );

        self::assertSame(77, $this->readId($request));
    }

    #[Test]
    public function approveMarksRequestAsApproved(): void
    {
        $approvedAt = new DateTimeImmutable('2026-02-03 04:05:06');
        $request = ChannelRegistrationRequest::request(
            new DateTimeImmutable('2026-01-02 03:04:05'),
            '#canal',
            1,
            'Nick',
        );

        $request->approve('OperNick', $approvedAt);

        self::assertSame(ChannelRegistrationStatus::Approved, $request->getStatus());
        self::assertTrue($request->isApproved());
        self::assertFalse($request->isPending());
        self::assertSame($approvedAt, $request->getApprovedAt());
        self::assertSame('OperNick', $request->getApprovedByNick());
        self::assertNull($request->getDeniedAt());
        self::assertNull($request->getDeniedByNick());
        self::assertNull($request->getDeniedReason());
    }

    #[Test]
    public function approveRejectsRequestThatIsNotPending(): void
    {
        $request = ChannelRegistrationRequest::request(
            new DateTimeImmutable('2026-01-02 03:04:05'),
            '#canal',
            1,
            'Nick',
        );
        $request->approve('OperNick', new DateTimeImmutable('2026-02-03 04:05:06'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only pending requests can be approved.');

        $request->approve('OtroOper', new DateTimeImmutable('2026-03-04 05:06:07'));
    }

    #[Test]
    public function denyMarksRequestAsDeniedWithReason(): void
    {
        $deniedAt = new DateTimeImmutable('2026-02-03 04:05:06');
        $request = ChannelRegistrationRequest::request(
            new DateTimeImmutable('2026-01-02 03:04:05'),
            '#canal',
            1,
            'Nick',
        );

        $request->deny('OperNick', 'Descripcion invalida', $deniedAt);

        self::assertSame(ChannelRegistrationStatus::Denied, $request->getStatus());
        self::assertTrue($request->isDenied());
        self::assertFalse($request->isPending());
        self::assertSame($deniedAt, $request->getDeniedAt());
        self::assertSame('OperNick', $request->getDeniedByNick());
        self::assertSame('Descripcion invalida', $request->getDeniedReason());
        self::assertNull($request->getApprovedAt());
        self::assertNull($request->getApprovedByNick());
    }

    #[Test]
    public function denyAcceptsNullReason(): void
    {
        $request = ChannelRegistrationRequest::request(
            new DateTimeImmutable('2026-01-02 03:04:05'),
            '#canal',
            1,
            'Nick',
        );

        $request->deny('OperNick', null, new DateTimeImmutable('2026-02-03 04:05:06'));

        self::assertNull($request->getDeniedReason());
    }

    #[Test]
    public function denyRejectsRequestThatIsNotPending(): void
    {
        $request = ChannelRegistrationRequest::request(
            new DateTimeImmutable('2026-01-02 03:04:05'),
            '#canal',
            1,
            'Nick',
        );
        $request->deny('OperNick', null, new DateTimeImmutable('2026-02-03 04:05:06'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Only pending requests can be denied.');

        $request->deny('OtroOper', null, new DateTimeImmutable('2026-03-04 05:06:07'));
    }

    /**
     * The identifier is assigned by the persistence layer (see config/doctrine),
     * so it is injected through reflection, mirroring the other entity tests.
     */
    private function readId(ChannelRegistrationRequest $request): int
    {
        $reflection = new ReflectionClass(ChannelRegistrationRequest::class);
        $reflection->getProperty('id')->setValue($request, 77);

        return $request->getId();
    }
}
