<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Security\Voter;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\Out\Security\Voter\ChanServLevelFounderVoter;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\ChanServOperatorAccess;
use App\ChanServ\Application\Security\ChanServPermission;
use App\Irc\Application\Port\In\SenderView;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[CoversClass(ChanServLevelFounderVoter::class)]
final class ChanServLevelFounderVoterTest extends TestCase
{
    private ChanServLevelFounderVoter $voter;

    /** @var Stub&ChanServOperatorAccess */
    private ChanServOperatorAccess $operatorAccess;

    protected function setUp(): void
    {
        $this->operatorAccess = $this->createStub(ChanServOperatorAccess::class);
        $this->voter = new ChanServLevelFounderVoter($this->operatorAccess);
    }

    #[Test]
    public function voteAbstainsForUnsupportedAttribute(): void
    {
        $context = $this->createChanServContext(null, null);
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, $context, ['chanserv.drop']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function voteAbstainsForWrongSubject(): void
    {
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, new stdClass(), [ChanServPermission::LEVEL_FOUNDER]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function voteDeniesWhenUserIsNotUserInterface(): void
    {
        $context = $this->createChanServContext(null, null);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $result = $this->voter->vote($token, $context, [ChanServPermission::LEVEL_FOUNDER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteGrantsForRootUser(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'RootAdmin',
            ident: 'root',
            hostname: 'root.local',
            cloakedHost: 'root.local',
            ipBase64: 'cm9vdA==',
            isIdentified: true,
            isOper: true
        );

        $account = new ChanAccountView(1, 'OperAdmin', 'en');

        $this->operatorAccess->method('isRoot')->willReturn(true);

        $context = $this->createChanServContext($sender, $account);
        $user = $this->createStub(UserInterface::class);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $context, [ChanServPermission::LEVEL_FOUNDER]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function voteDeniesForRootUserNotIdentifiedAndNotOper(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'RootAdmin',
            ident: 'root',
            hostname: 'root.local',
            cloakedHost: 'root.local',
            ipBase64: 'cm9vdA==',
            isIdentified: false,
            isOper: false
        );

        $account = new ChanAccountView(1, 'RootAdmin', 'en');

        $context = $this->createChanServContext($sender, $account);
        $user = $this->createStub(UserInterface::class);
        $user->method('getRoles')->willReturn([]);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $context, [ChanServPermission::LEVEL_FOUNDER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteDeniesWhenUserDoesNotHaveOperRole(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'RegularUser',
            ident: 'user',
            hostname: 'user.local',
            cloakedHost: 'user.local',
            ipBase64: 'dXNlcg==',
            isIdentified: true,
            isOper: false
        );

        $account = new ChanAccountView(1, 'RegularUser', 'en');

        $this->operatorAccess->method('isRoot')->willReturn(false);

        $context = $this->createChanServContext($sender, $account);
        $user = $this->createStub(UserInterface::class);
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $context, [ChanServPermission::LEVEL_FOUNDER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteDeniesWhenContextHasNoSenderAccount(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'OperUser',
            ident: 'oper',
            hostname: 'oper.local',
            cloakedHost: 'oper.local',
            ipBase64: 'b3Blcg==',
            isIdentified: true,
            isOper: true
        );

        $this->operatorAccess->method('isRoot')->willReturn(false);

        $context = $this->createChanServContext($sender, null);
        $user = $this->createStub(UserInterface::class);
        $user->method('getRoles')->willReturn(['ROLE_OPER']);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $context, [ChanServPermission::LEVEL_FOUNDER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteGrantsWhenIrcopHasLevelFounderPermission(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'OperUser',
            ident: 'oper',
            hostname: 'oper.local',
            cloakedHost: 'oper.local',
            ipBase64: 'b3Blcg==',
            isIdentified: true,
            isOper: true
        );

        $account = new ChanAccountView(1, 'OperUser', 'en');

        $operatorAccess = $this->createMock(ChanServOperatorAccess::class);
        $operatorAccess->method('isRoot')->willReturn(false);
        $operatorAccess->expects(self::once())
            ->method('hasPermission')
            ->with(1, 'operuser', ChanServPermission::LEVEL_FOUNDER)
            ->willReturn(true);

        $voter = new ChanServLevelFounderVoter($operatorAccess);

        $context = $this->createChanServContext($sender, $account);
        $user = $this->createStub(UserInterface::class);
        $user->method('getRoles')->willReturn(['ROLE_OPER']);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $voter->vote($token, $context, [ChanServPermission::LEVEL_FOUNDER]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function voteDeniesWhenIrcopLacksLevelFounderPermission(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'OperUser',
            ident: 'oper',
            hostname: 'oper.local',
            cloakedHost: 'oper.local',
            ipBase64: 'b3Blcg==',
            isIdentified: true,
            isOper: true
        );

        $account = new ChanAccountView(1, 'OperUser', 'en');

        $operatorAccess = $this->createMock(ChanServOperatorAccess::class);
        $operatorAccess->method('isRoot')->willReturn(false);
        $operatorAccess->expects(self::once())
            ->method('hasPermission')
            ->with(1, 'operuser', ChanServPermission::LEVEL_FOUNDER)
            ->willReturn(false);

        $voter = new ChanServLevelFounderVoter($operatorAccess);

        $context = $this->createChanServContext($sender, $account);
        $user = $this->createStub(UserInterface::class);
        $user->method('getRoles')->willReturn(['ROLE_OPER']);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $voter->vote($token, $context, [ChanServPermission::LEVEL_FOUNDER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    private function createChanServContext(?SenderView $sender, ?ChanAccountView $account): ChanServContext
    {
        $reflection = new ReflectionClass(ChanServContext::class);
        $context = $reflection->newInstanceWithoutConstructor();

        $senderProp = $reflection->getProperty('sender');
        $senderProp->setValue($context, $sender);

        $accountProp = $reflection->getProperty('senderAccount');
        $accountProp->setValue($context, $account);

        return $context;
    }
}
