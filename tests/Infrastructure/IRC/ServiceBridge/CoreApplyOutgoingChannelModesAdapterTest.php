<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Infrastructure\IRC\Network\ApplyOutgoingChannelModesApplicatorInterface;
use App\Infrastructure\IRC\ServiceBridge\CoreApplyOutgoingChannelModesAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CoreApplyOutgoingChannelModesAdapter::class)]
final class CoreApplyOutgoingChannelModesAdapterTest extends TestCase
{
    #[Test]
    public function applyOutgoingChannelModesDelegatesToApplicator(): void
    {
        $applicator = $this->createMock(ApplyOutgoingChannelModesApplicatorInterface::class);
        $applicator->expects(self::once())
            ->method('applyOutgoingChannelModes')
            ->with('#test', '+o', ['001ABC']);
        $adapter = new CoreApplyOutgoingChannelModesAdapter($applicator);

        $adapter->applyOutgoingChannelModes('#test', '+o', ['001ABC']);
    }
}
