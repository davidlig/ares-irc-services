<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Security;

use App\ChanServ\Adapter\Out\Security\NickServChanAuthorizationContextAdapter;
use App\NickServ\Application\Port\In\IrcAuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServChanAuthorizationContextAdapter::class)]
final class NickServChanAuthorizationContextAdapterTest extends TestCase
{
    #[Test]
    public function setCurrentUserDelegatesToNickServContext(): void
    {
        $context = $this->createMock(IrcAuthorizationContext::class);
        $context->expects(self::once())
            ->method('setCurrentUser')
            ->with('001AAAAAA', true, false);

        $adapter = new NickServChanAuthorizationContextAdapter($context);
        $adapter->setCurrentUser('001AAAAAA', true, false);
    }

    #[Test]
    public function clearDelegatesToNickServContext(): void
    {
        $context = $this->createMock(IrcAuthorizationContext::class);
        $context->expects(self::once())
            ->method('clear');

        $adapter = new NickServChanAuthorizationContextAdapter($context);
        $adapter->clear();
    }
}
