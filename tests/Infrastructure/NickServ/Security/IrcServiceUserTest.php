<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Security;

use App\Application\Port\SenderView;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcServiceUser::class)]
final class IrcServiceUserTest extends TestCase
{
    #[Test]
    public function getRolesReturnsUserOnlyWhenNotIdentifiedNorOper(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', false, false);
        $user = new IrcServiceUser($sender);

        self::assertSame([IrcServiceUser::ROLE_USER], $user->getRoles());
    }

    #[Test]
    public function getRolesIncludesIdentifiedWhenSenderIsIdentified(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false);
        $user = new IrcServiceUser($sender);

        self::assertContains(IrcServiceUser::ROLE_USER, $user->getRoles());
        self::assertContains(IrcServiceUser::ROLE_IDENTIFIED, $user->getRoles());
    }

    #[Test]
    public function getRolesIncludesOperWhenSenderIsOper(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', false, true);
        $user = new IrcServiceUser($sender);

        self::assertContains(IrcServiceUser::ROLE_OPER, $user->getRoles());
    }

    #[Test]
    public function getUserIdentifierReturnsSenderUid(): void
    {
        $sender = new SenderView('UID123', 'Nick', 'i', 'h', 'c', 'ip', false, false);
        $user = new IrcServiceUser($sender);

        self::assertSame('UID123', $user->getUserIdentifier());
    }

    #[Test]
    public function getSenderViewReturnsInjectedSender(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', false, false);
        $user = new IrcServiceUser($sender);

        self::assertSame($sender, $user->getSenderView());
    }

    #[Test]
    public function eraseCredentialsDoesNothing(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', false, false);
        $user = new IrcServiceUser($sender);

        $user->eraseCredentials();

        self::assertSame($sender, $user->getSenderView());
    }
}
