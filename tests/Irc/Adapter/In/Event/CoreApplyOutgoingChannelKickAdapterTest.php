<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Event;

use App\Irc\Adapter\In\Event\CoreApplyOutgoingChannelKickAdapter;
use App\Irc\Adapter\Network\ApplyOutgoingChannelKickApplicatorInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CoreApplyOutgoingChannelKickAdapter::class)]
final class CoreApplyOutgoingChannelKickAdapterTest extends TestCase
{
    #[Test]
    public function applyOutgoingKickDelegatesToApplicator(): void
    {
        $applicator = $this->createMock(ApplyOutgoingChannelKickApplicatorInterface::class);
        $applicator->expects(self::once())
            ->method('applyOutgoingKick')
            ->with('#test', '001ABC', 'Test reason');
        $adapter = new CoreApplyOutgoingChannelKickAdapter($applicator);

        $adapter->applyOutgoingKick('#test', '001ABC', 'Test reason');
    }
}
