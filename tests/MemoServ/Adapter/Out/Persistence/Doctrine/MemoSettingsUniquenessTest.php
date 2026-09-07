<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\Persistence\Doctrine;

use App\MemoServ\Adapter\Out\Persistence\Doctrine\MemoSettingsDoctrineRepository;
use App\MemoServ\Domain\Entity\MemoSettings;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(MemoSettingsDoctrineRepository::class)]
#[CoversClass(MemoSettings::class)]
#[Group('integration')]
final class MemoSettingsUniquenessTest extends DoctrineIntegrationTestCase
{
    #[Test]
    public function mappingEnforcesOneSettingsRowPerNick(): void
    {
        $repository = new MemoSettingsDoctrineRepository($this->entityManager);
        $repository->save(new MemoSettings(targetNickId: 42, targetChannelId: null, enabled: true));

        $this->expectException(UniqueConstraintViolationException::class);

        $repository->save(new MemoSettings(targetNickId: 42, targetChannelId: null, enabled: false));
    }

    #[Test]
    public function mappingEnforcesOneSettingsRowPerChannel(): void
    {
        $repository = new MemoSettingsDoctrineRepository($this->entityManager);
        $repository->save(new MemoSettings(targetNickId: null, targetChannelId: 73, enabled: true));

        $this->expectException(UniqueConstraintViolationException::class);

        $repository->save(new MemoSettings(targetNickId: null, targetChannelId: 73, enabled: false));
    }
}
