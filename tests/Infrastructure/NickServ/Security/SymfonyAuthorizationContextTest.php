<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Security;

use App\Application\Port\SenderView;
use App\Infrastructure\NickServ\Security\SymfonyAuthorizationContext;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[CoversClass(SymfonyAuthorizationContext::class)]
final class SymfonyAuthorizationContextTest extends TestCase
{
    #[Test]
    public function setCurrentUserStoresIrcServiceToken(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', false, false);

        $tokenStorage->expects(self::once())
            ->method('setToken')
            ->with(self::callback(static fn ($token): bool => $token instanceof \App\Infrastructure\NickServ\Security\IrcServiceToken));

        $context = new SymfonyAuthorizationContext($tokenStorage);
        $context->setCurrentUser($sender);
    }

    #[Test]
    public function clearRemovesToken(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects(self::once())->method('setToken')->with(null);

        $context = new SymfonyAuthorizationContext($tokenStorage);
        $context->clear();
    }
}
