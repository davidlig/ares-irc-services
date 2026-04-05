<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Security\Voter;

use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use App\Infrastructure\NickServ\Security\Voter\NickServSasetVoter;
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

    private IrcopAccessHelper $accessHelper;

    private RootUserRegistry $rootRegistry;

    private OperIrcopRepositoryInterface $ircopRepository;

    protected function setUp(): void
    {
        $this->rootRegistry = new RootUserRegistry('RootAdmin');
        $this->ircopRepository = $this->createStub(OperIrcopRepositoryInterface::class);
        $this->accessHelper = new IrcopAccessHelper(
            $this->rootRegistry,
            $this->ircopRepository,
            $this->createStub(\App\Domain\OperServ\Repository\OperRoleRepositoryInterface::class)
        );
        $this->voter = new NickServSasetVoter($this->accessHelper, $this->rootRegistry, $this->ircopRepository);
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

        $role = $this->createStub(\App\Domain\OperServ\Entity\OperRole::class);
        $role->method('getId')->willReturn(1);

        $ircop = $this->createStub(\App\Domain\OperServ\Entity\OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $roleRepository = $this->createMock(\App\Domain\OperServ\Repository\OperRoleRepositoryInterface::class);
        $roleRepository->expects(self::once())->method('hasPermission')->with(1, NickServPermission::SASET)->willReturn(true);

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository->expects(self::once())->method('findByNickId')->with(1)->willReturn($ircop);

        $accessHelper = new IrcopAccessHelper(
            $this->rootRegistry,
            $ircopRepository,
            $roleRepository
        );

        $voter = new NickServSasetVoter($accessHelper, $this->rootRegistry, $ircopRepository);

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

        $role = $this->createStub(\App\Domain\OperServ\Entity\OperRole::class);
        $role->method('getId')->willReturn(1);

        $ircop = $this->createStub(\App\Domain\OperServ\Entity\OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $roleRepository = $this->createMock(\App\Domain\OperServ\Repository\OperRoleRepositoryInterface::class);
        $roleRepository->expects(self::once())->method('hasPermission')->with(1, NickServPermission::SASET)->willReturn(false);

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository->expects(self::once())->method('findByNickId')->with(1)->willReturn($ircop);

        $accessHelper = new IrcopAccessHelper(
            $this->rootRegistry,
            $ircopRepository,
            $roleRepository
        );

        $voter = new NickServSasetVoter($accessHelper, $this->rootRegistry, $ircopRepository);

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

        $roleRepository = $this->createStub(\App\Domain\OperServ\Repository\OperRoleRepositoryInterface::class);

        $ircopRepository = $this->createMock(OperIrcopRepositoryInterface::class);
        $ircopRepository->expects(self::once())->method('findByNickId')->with(1)->willReturn(null);

        $accessHelper = new IrcopAccessHelper(
            $this->rootRegistry,
            $ircopRepository,
            $roleRepository
        );

        $voter = new NickServSasetVoter($accessHelper, $this->rootRegistry, $ircopRepository);

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
        $senderProp->setAccessible(true);
        $senderProp->setValue($context, $sender);

        $accountProp = $reflection->getProperty('senderAccount');
        $accountProp->setAccessible(true);
        $accountProp->setValue($context, $account);

        return $context;
    }
}
