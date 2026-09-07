<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Security\Voter;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\Out\Security\IrcServiceUser;
use App\NickServ\Adapter\Out\Security\Voter\NickServSasetVoter;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Domain\Entity\RegisteredNick;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(NickServSasetVoter::class)]
final class NickServSasetVoterTest extends TestCase
{
    private NickServSasetVoter $voter;

    private NickServOperatorAccess $operatorAccess;

    protected function setUp(): void
    {
        $this->operatorAccess = $this->createStub(NickServOperatorAccess::class);
        $this->operatorAccess->method('isRoot')->willReturnCallback(static fn (string $nick): bool => 'rootadmin' === $nick);
        $this->voter = new NickServSasetVoter($this->operatorAccess);
    }

    #[Test]
    public function voteAbstainsForUnsupportedAttribute(): void
    {
        $context = $this->createNickServContext(null, null);
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, $context, ['OTHER_ATTRIBUTE']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function voteAbstainsForWrongSubject(): void
    {
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, new stdClass(), [NickServPermission::SASET]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function voteDeniesWhenUserIsNotIrcServiceUser(): void
    {
        $context = $this->createNickServContext(null, null);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $result = $this->voter->vote($token, $context, [NickServPermission::SASET]);

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

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $context = $this->createNickServContext($sender, $account);
        $user = new IrcServiceUser($sender);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $context, [NickServPermission::SASET]);

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

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $context = $this->createNickServContext($sender, $account);
        $user = new IrcServiceUser($sender);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $context, [NickServPermission::SASET]);

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

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $context = $this->createNickServContext($sender, $account);
        $user = new IrcServiceUser($sender);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $context, [NickServPermission::SASET]);

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

        $context = $this->createNickServContext($sender, null);
        $user = new IrcServiceUser($sender);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $this->voter->vote($token, $context, [NickServPermission::SASET]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteGrantsWhenIrcopHasSasetPermission(): void
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

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $operatorAccess = $this->createMock(NickServOperatorAccess::class);
        $operatorAccess->expects(self::once())->method('isRoot')->with('operuser')->willReturn(false);
        $operatorAccess->expects(self::once())->method('hasPermission')->with(1, 'operuser', NickServPermission::SASET)->willReturn(true);
        $voter = new NickServSasetVoter($operatorAccess);

        $context = $this->createNickServContext($sender, $account);
        $user = new IrcServiceUser($sender);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $voter->vote($token, $context, [NickServPermission::SASET]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function voteDeniesWhenIrcopLacksSasetPermission(): void
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

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $operatorAccess = $this->createMock(NickServOperatorAccess::class);
        $operatorAccess->expects(self::once())->method('isRoot')->with('operuser')->willReturn(false);
        $operatorAccess->expects(self::once())->method('hasPermission')->with(1, 'operuser', NickServPermission::SASET)->willReturn(false);
        $voter = new NickServSasetVoter($operatorAccess);

        $context = $this->createNickServContext($sender, $account);
        $user = new IrcServiceUser($sender);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $voter->vote($token, $context, [NickServPermission::SASET]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteDeniesWhenIrcopNotFound(): void
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

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $operatorAccess = $this->createMock(NickServOperatorAccess::class);
        $operatorAccess->expects(self::once())->method('isRoot')->with('operuser')->willReturn(false);
        $operatorAccess->expects(self::once())->method('hasPermission')->with(1, 'operuser', NickServPermission::SASET)->willReturn(false);
        $voter = new NickServSasetVoter($operatorAccess);

        $context = $this->createNickServContext($sender, $account);
        $user = new IrcServiceUser($sender);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $voter->vote($token, $context, [NickServPermission::SASET]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    private function createNickServContext(?SenderView $sender, ?RegisteredNick $account): NickServContext
    {
        $reflection = new ReflectionClass(NickServContext::class);
        $context = $reflection->newInstanceWithoutConstructor();

        $senderProp = $reflection->getProperty('sender');
        $senderProp->setValue($context, $sender);

        $accountProp = $reflection->getProperty('senderAccount');
        $accountProp->setValue($context, $account);

        return $context;
    }
}
