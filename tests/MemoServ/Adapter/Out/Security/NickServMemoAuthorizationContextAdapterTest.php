<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\Security;

use App\MemoServ\Adapter\Out\Security\NickServMemoAuthorizationContextAdapter;
use App\NickServ\Application\Port\Out\AuthorizationContextInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NickServMemoAuthorizationContextAdapter::class)]
final class NickServMemoAuthorizationContextAdapterTest extends TestCase
{
    #[Test]
    public function setCurrentUserDelegatesToNickServContext(): void
    {
        $context = $this->createMock(AuthorizationContextInterface::class);
        $context->expects(self::once())
            ->method('setCurrentUser')
            ->with('001AAAAAA', true, false);

        $adapter = new NickServMemoAuthorizationContextAdapter($context);
        $adapter->setCurrentUser('001AAAAAA', true, false);
    }

    #[Test]
    public function clearDelegatesToNickServContext(): void
    {
        $context = $this->createMock(AuthorizationContextInterface::class);
        $context->expects(self::once())
            ->method('clear');

        $adapter = new NickServMemoAuthorizationContextAdapter($context);
        $adapter->clear();
    }
}
