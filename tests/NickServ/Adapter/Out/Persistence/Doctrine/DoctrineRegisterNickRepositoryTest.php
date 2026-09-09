<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Persistence\Doctrine;

use App\NickServ\Adapter\Out\Persistence\Doctrine\DoctrineRegisterNickRepository;
use App\NickServ\Application\Port\Out\RegisterNickRepository;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DoctrineRegisterNickRepository::class)]
#[Group('integration')]
final class DoctrineRegisterNickRepositoryTest extends DoctrineIntegrationTestCase
{
    private RegisterNickRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new DoctrineRegisterNickRepository($this->entityManager);
    }

    #[Test]
    public function persistsAndFindsRegistrationCaseInsensitively(): void
    {
        $nick = RegisteredNick::createPending(
            'MixedNick',
            'hash',
            'User@Example.com',
            'en',
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable(),
        );

        $this->repository->save($nick);
        $this->flushAndClear();

        self::assertSame('MixedNick', $this->repository->findByNick('mixednick')?->getNickname());
        self::assertSame('MixedNick', $this->repository->findByEmail('user@example.COM')?->getNickname());
    }

    #[Test]
    public function returnsNullWhenRegistrationDoesNotExist(): void
    {
        self::assertNull($this->repository->findByNick('missing'));
        self::assertNull($this->repository->findByEmail('missing@example.com'));
    }
}
