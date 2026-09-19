<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\ServiceBridge;

use App\Irc\Adapter\ServiceBridge\CoreBurstCompleteAdapter;
use App\Irc\Application\BurstCompleteRegistry;
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
