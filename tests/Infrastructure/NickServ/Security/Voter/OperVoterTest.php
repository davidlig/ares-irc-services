<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Security\Voter;

use App\Application\NickServ\Security\NickServPermission;
use App\Application\Port\SenderView;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use App\Infrastructure\NickServ\Security\Voter\OperVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(OperVoter::class)]
final class OperVoterTest extends TestCase
{
    private OperVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new OperVoter();
    }

    #[Test]
    public function voteGrantsAccessForOperUser(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'OperUser',
            ident: 'oper',
            hostname: 'oper.local',
            cloakedHost: 'oper.local',
            ipBase64: 'b3Blcg==',
            isIdentified: true,
            isOper: true,
        );

        $user = new IrcServiceUser($sender);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, null, [NickServPermission::NETWORK_OPER]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function voteDeniesAccessForNonOperUser(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'RegularUser',
            ident: 'user',
            hostname: 'user.local',
            cloakedHost: 'user.local',
            ipBase64: 'dXNlcg==',
            isIdentified: true,
            isOper: false,
        );

        $user = new IrcServiceUser($sender);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, null, [NickServPermission::NETWORK_OPER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteDeniesAccessWhenUserNotIrcServiceUser(): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $result = $this->voter->vote($token, null, [NickServPermission::NETWORK_OPER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteAbstainsForUnsupportedAttribute(): void
    {
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, null, ['OTHER_ATTRIBUTE']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function ircServiceUserReturnsCorrectRolesForOper(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'OperUser',
            ident: 'oper',
            hostname: 'oper.local',
            cloakedHost: 'oper.local',
            ipBase64: 'b3Blcg==',
            isIdentified: true,
            isOper: true,
        );

        $user = new IrcServiceUser($sender);

        self::assertContains(IrcServiceUser::ROLE_OPER, $user->getRoles());
        self::assertContains(IrcServiceUser::ROLE_IDENTIFIED, $user->getRoles());
        self::assertContains(IrcServiceUser::ROLE_USER, $user->getRoles());
    }

    #[Test]
    public function ircServiceUserReturnsCorrectRolesForNonOper(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'RegularUser',
            ident: 'user',
            hostname: 'user.local',
            cloakedHost: 'user.local',
            ipBase64: 'dXNlcg==',
            isIdentified: true,
            isOper: false,
        );

        $user = new IrcServiceUser($sender);

        self::assertNotContains(IrcServiceUser::ROLE_OPER, $user->getRoles());
        self::assertContains(IrcServiceUser::ROLE_IDENTIFIED, $user->getRoles());
        self::assertContains(IrcServiceUser::ROLE_USER, $user->getRoles());
    }
}
