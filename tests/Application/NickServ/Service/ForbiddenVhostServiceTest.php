<?php

declare(strict_types=1);

namespace App\Tests\Application\NickServ\Service;

use App\Application\NickServ\Service\ForbiddenVhostService;
use App\Domain\NickServ\Entity\ForbiddenVhost;
use App\Domain\NickServ\Repository\ForbiddenVhostRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ForbiddenVhostService::class)]
final class ForbiddenVhostServiceTest extends TestCase
{
    private ForbiddenVhostRepositoryInterface&MockObject $repository;

    private ForbiddenVhostService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ForbiddenVhostRepositoryInterface::class);
        $this->service = new ForbiddenVhostService($this->repository);
    }

    public function testForbidCreatesAndSavesForbiddenVhost(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('save')
            ->with(self::callback(static fn (ForbiddenVhost $vhost) => '*.pirated.com' === $vhost->getPattern()
                    && 123 === $vhost->getCreatedByNickId()));

        $result = $this->service->forbid('*.pirated.com', 123);

        self::assertSame('*.pirated.com', $result->getPattern());
        self::assertSame(123, $result->getCreatedByNickId());
    }

    public function testUnforbidRemovesExistingPattern(): void
    {
        $forbidden = ForbiddenVhost::create('*.pirated.com', 123);

        $this->repository
            ->expects(self::once())
            ->method('findByPattern')
            ->with('*.pirated.com')
            ->willReturn($forbidden);

        $this->repository
            ->expects(self::once())
            ->method('remove')
            ->with($forbidden);

        $result = $this->service->unforbid('*.pirated.com');

        self::assertTrue($result);
    }

    public function testUnforbidReturnsFalseWhenNotFound(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('findByPattern')
            ->with('*.pirated.com')
            ->willReturn(null);

        $this->repository
            ->expects(self::never())
            ->method('remove');

        $result = $this->service->unforbid('*.pirated.com');

        self::assertFalse($result);
    }

    public function testMatchesForbiddenPatternReturnsTrueWhenMatch(): void
    {
        $forbidden1 = ForbiddenVhost::create('*.pirated.com', 1);
        $forbidden2 = ForbiddenVhost::create('badhost.*', 2);

        $this->repository
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([$forbidden1, $forbidden2]);

        $result = $this->service->matchesForbiddenPattern('evil.pirated.com');

        self::assertTrue($result);
    }

    public function testMatchesForbiddenPatternReturnsFalseWhenNoMatch(): void
    {
        $forbidden1 = ForbiddenVhost::create('*.pirated.com', 1);
        $forbidden2 = ForbiddenVhost::create('badhost.*', 2);

        $this->repository
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([$forbidden1, $forbidden2]);

        $result = $this->service->matchesForbiddenPattern('goodhost.com');

        self::assertFalse($result);
    }

    public function testMatchesForbiddenPatternReturnsFalseWhenEmptyList(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        $result = $this->service->matchesForbiddenPattern('anyhost.com');

        self::assertFalse($result);
    }

    public function testGetAllReturnsAllForbiddenPatterns(): void
    {
        $forbidden1 = ForbiddenVhost::create('*.pirated.com', 1);
        $forbidden2 = ForbiddenVhost::create('badhost.*', 2);

        $this->repository
            ->expects(self::once())
            ->method('findAll')
            ->willReturn([$forbidden1, $forbidden2]);

        $result = $this->service->getAll();

        self::assertCount(2, $result);
        self::assertSame('*.pirated.com', $result[0]->getPattern());
        self::assertSame('badhost.*', $result[1]->getPattern());
    }

    public function testCountReturnsRepositoryCount(): void
    {
        $this->repository
            ->expects(self::once())
            ->method('countAll')
            ->willReturn(5);

        $result = $this->service->count();

        self::assertSame(5, $result);
    }
}
