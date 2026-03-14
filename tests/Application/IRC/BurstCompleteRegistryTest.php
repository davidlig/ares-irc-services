<?php

declare(strict_types=1);

namespace App\Tests\Application\IRC;

use App\Application\IRC\BurstCompleteRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(BurstCompleteRegistry::class)]
final class BurstCompleteRegistryTest extends TestCase
{
    private BurstCompleteRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new BurstCompleteRegistry();
    }

    #[Test]
    public function isBurstCompleteReturnsFalseInitially(): void
    {
        self::assertFalse($this->registry->isBurstComplete());
    }

    #[Test]
    public function setBurstCompleteSetsCompleted(): void
    {
        $this->registry->setBurstComplete(true);

        self::assertTrue($this->registry->isBurstComplete());
    }

    #[Test]
    public function setBurstCompleteSetsIncomplete(): void
    {
        $this->registry->setBurstComplete(true);
        $this->registry->setBurstComplete(false);

        self::assertFalse($this->registry->isBurstComplete());
    }

    #[Test]
    public function canToggleMultipleTimes(): void
    {
        self::assertFalse($this->registry->isBurstComplete());

        $this->registry->setBurstComplete(true);
        self::assertTrue($this->registry->isBurstComplete());

        $this->registry->setBurstComplete(false);
        self::assertFalse($this->registry->isBurstComplete());

        $this->registry->setBurstComplete(true);
        self::assertTrue($this->registry->isBurstComplete());
    }
}
