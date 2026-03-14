<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\MemoServ\Doctrine;

use App\Domain\MemoServ\Entity\MemoSettings;
use App\Domain\MemoServ\Repository\MemoSettingsRepositoryInterface;
use App\Infrastructure\MemoServ\Doctrine\MemoSettingsDoctrineRepository;
use App\Tests\Integration\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(MemoSettingsDoctrineRepository::class)]
#[Group('integration')]
final class MemoSettingsDoctrineRepositoryTest extends DoctrineIntegrationTestCase
{
    private MemoSettingsRepositoryInterface $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MemoSettingsDoctrineRepository($this->entityManager);
    }

    #[Test]
    public function savePersistsSettingsForNick(): void
    {
        $settings = new MemoSettings(targetNickId: 1, targetChannelId: null, enabled: false);

        $this->repository->save($settings);
        $this->flushAndClear();

        $found = $this->repository->findByTargetNick(1);

        self::assertNotNull($found);
        self::assertFalse($found->isEnabled());
    }

    #[Test]
    public function savePersistsSettingsForChannel(): void
    {
        $settings = new MemoSettings(targetNickId: null, targetChannelId: 10, enabled: false);

        $this->repository->save($settings);
        $this->flushAndClear();

        $found = $this->repository->findByTargetChannel(10);

        self::assertNotNull($found);
        self::assertFalse($found->isEnabled());
    }

    #[Test]
    public function findByTargetNickReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByTargetNick(999));
    }

    #[Test]
    public function findByTargetChannelReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByTargetChannel(999));
    }

    #[Test]
    public function isEnabledForNickReturnsTrueWhenNoSettings(): void
    {
        self::assertTrue($this->repository->isEnabledForNick(999));
    }

    #[Test]
    public function isEnabledForNickReturnsFalseWhenDisabled(): void
    {
        $settings = new MemoSettings(targetNickId: 1, targetChannelId: null, enabled: false);
        $this->repository->save($settings);
        $this->flushAndClear();

        self::assertFalse($this->repository->isEnabledForNick(1));
    }

    #[Test]
    public function isEnabledForNickReturnsTrueWhenEnabled(): void
    {
        $settings = new MemoSettings(targetNickId: 1, targetChannelId: null, enabled: true);
        $this->repository->save($settings);
        $this->flushAndClear();

        self::assertTrue($this->repository->isEnabledForNick(1));
    }

    #[Test]
    public function isEnabledForChannelReturnsTrueWhenNoSettings(): void
    {
        self::assertTrue($this->repository->isEnabledForChannel(999));
    }

    #[Test]
    public function isEnabledForChannelReturnsFalseWhenDisabled(): void
    {
        $settings = new MemoSettings(targetNickId: null, targetChannelId: 10, enabled: false);
        $this->repository->save($settings);
        $this->flushAndClear();

        self::assertFalse($this->repository->isEnabledForChannel(10));
    }

    #[Test]
    public function deleteRemovesSettings(): void
    {
        $settings = new MemoSettings(targetNickId: 1, targetChannelId: null, enabled: false);
        $this->repository->save($settings);
        $this->entityManager->flush();

        $this->repository->delete($settings);
        $this->flushAndClear();

        self::assertNull($this->repository->findByTargetNick(1));
    }

    #[Test]
    public function deleteAllForNickRemovesAllSettings(): void
    {
        $settings1 = new MemoSettings(targetNickId: 1, targetChannelId: null, enabled: false);
        $settings2 = new MemoSettings(targetNickId: 2, targetChannelId: null, enabled: false);

        $this->repository->save($settings1);
        $this->repository->save($settings2);
        $this->flushAndClear();

        $this->repository->deleteAllForNick(1);
        $this->flushAndClear();

        self::assertNull($this->repository->findByTargetNick(1));
        self::assertNotNull($this->repository->findByTargetNick(2));
    }

    #[Test]
    public function deleteAllForChannelRemovesAllSettings(): void
    {
        $settings1 = new MemoSettings(targetNickId: null, targetChannelId: 10, enabled: false);
        $settings2 = new MemoSettings(targetNickId: null, targetChannelId: 20, enabled: false);

        $this->repository->save($settings1);
        $this->repository->save($settings2);
        $this->flushAndClear();

        $this->repository->deleteAllForChannel(10);
        $this->flushAndClear();

        self::assertNull($this->repository->findByTargetChannel(10));
        self::assertNotNull($this->repository->findByTargetChannel(20));
    }
}
