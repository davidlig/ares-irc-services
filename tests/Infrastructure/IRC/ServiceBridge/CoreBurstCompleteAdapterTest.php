<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Application\IRC\BurstCompleteRegistry;
use App\Infrastructure\IRC\ServiceBridge\CoreBurstCompleteAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CoreBurstCompleteAdapter::class)]
final class CoreBurstCompleteAdapterTest extends TestCase
{
    #[Test]
    public function isCompleteDelegatesToBurstCompleteRegistry(): void
    {
        $registry = new BurstCompleteRegistry();
        $adapter = new CoreBurstCompleteAdapter($registry);
        self::assertFalse($adapter->isComplete());

        $registry->setBurstComplete(true);
        self::assertTrue($adapter->isComplete());
    }
}
