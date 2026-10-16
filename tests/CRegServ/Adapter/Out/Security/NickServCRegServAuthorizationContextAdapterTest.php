<?php

declare(strict_types=1);

namespace App\Tests\CRegServ\Adapter\Out\Security;

use App\CRegServ\Adapter\Out\Security\NickServCRegServAuthorizationContextAdapter;
use App\NickServ\Application\Port\In\IrcAuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServCRegServAuthorizationContextAdapter::class)]
final class NickServCRegServAuthorizationContextAdapterTest extends TestCase
{
    #[Test]
    public function setCurrentUserDelegatesToNickServContext(): void
    {
        $context = $this->createMock(IrcAuthorizationContext::class);
        $context->expects(self::once())
            ->method('setCurrentUser')
            ->with('001AAAAAA', true, false);

        $adapter = new NickServCRegServAuthorizationContextAdapter($context);
        $adapter->setCurrentUser('001AAAAAA', true, false);
    }

    #[Test]
    public function clearDelegatesToNickServContext(): void
    {
        $context = $this->createMock(IrcAuthorizationContext::class);
        $context->expects(self::once())
            ->method('clear');

        $adapter = new NickServCRegServAuthorizationContextAdapter($context);
        $adapter->clear();
    }
}
