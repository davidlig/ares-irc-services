<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Security;

use App\Application\Port\SenderView;
use App\Infrastructure\NickServ\Security\IrcServiceToken;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IrcServiceToken::class)]
final class IrcServiceTokenTest extends TestCase
{
    #[Test]
    public function constructionAndToString(): void
    {
        $sender = new SenderView('UID1', 'Nick', 'i', 'h', 'c', 'ip', true, false);
        $user = new IrcServiceUser($sender);
        $token = new IrcServiceToken($user);

        self::assertSame($user, $token->getUser());
        $str = $token->__toString();
        self::assertStringContainsString('UID1', $str);
        self::assertStringContainsString('IrcServiceToken', $str);
    }
}
