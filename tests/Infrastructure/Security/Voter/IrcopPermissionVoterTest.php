<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security\Voter;

use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Application\Security\IrcopContextInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use App\Infrastructure\Security\Voter\IrcopPermissionVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(IrcopPermissionVoter::class)]
final class IrcopPermissionVoterTest extends TestCase
{
    #[Test]
    public function supportsPermissionInBotCommandFormat(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createStub(IrcopContextInterface::class);

        self::assertTrue(VoterInterface::ACCESS_ABSTAIN !== $voter->vote($this->createTokenWithOperUser(false), $context, ['NICKSERV_DROP']));
        self::assertTrue(VoterInterface::ACCESS_ABSTAIN !== $voter->vote($this->createTokenWithOperUser(false), $context, ['CHANSERV_SUSPEND']));
        self::assertTrue(VoterInterface::ACCESS_ABSTAIN !== $voter->vote($this->createTokenWithOperUser(false), $context, ['OPERSERV_ADMIN']));
    }

    #[Test]
    public function abstainsForLowercasePermission(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createStub(IrcopContextInterface::class);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->createTokenWithOperUser(false), $context, ['nickserv_drop']));
    }

    #[Test]
    public function abstainsForNonContextSubject(): void
    {
        $voter = $this->createVoterWithoutRoots();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->createTokenWithOperUser(false), new stdClass(), ['NICKSERV_DROP']));
    }

    #[Test]
    public function deniesAccessForNonIrcServiceUser(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createStub(IrcopContextInterface::class);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $context, ['NICKSERV_DROP']));
    }

    #[Test]
    public function deniesAccessForNonOperUser(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createStub(IrcopContextInterface::class);
        $token = $this->createTokenWithOperUser(false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $context, ['NICKSERV_DROP']));
    }

    #[Test]
    public function grantsAccessForRootUser(): void
    {
        $rootRegistry = new RootUserRegistry('testnick');
        $accessHelper = new IrcopAccessHelper(
            $rootRegistry,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class)
        );

        $voter = new IrcopPermissionVoter(
            $accessHelper,
            $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class)
        );

        $context = $this->createStub(IrcopContextInterface::class);
        $token = $this->createTokenWithOperUser(true, 'testnick');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $context, ['NICKSERV_DROP']));
    }

    #[Test]
    public function grantsAccessForIrcopWithPermission(): void
    {
        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(5);

        $operIrcop = OperIrcop::create(1, $role, null, null);

        $rootRegistry = new RootUserRegistry(''); // No roots
        $operIrcopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $operIrcopRepo->method('findByNickId')->willReturn($operIrcop);

        // Mock roleRepository to return true for NICKSERV_DROP permission
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('hasPermission')->willReturnMap([
            [5, 'NICKSERV_DROP', true],
        ]);

        $accessHelper = new IrcopAccessHelper($rootRegistry, $operIrcopRepo, $roleRepo);

        $voter = new IrcopPermissionVoter(
            $accessHelper,
            $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class)
        );

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $context = $this->createStub(IrcopContextInterface::class);
        $context->method('getSenderAccount')->willReturn($account);

        $token = $this->createTokenWithOperUser(true, 'testnick');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $context, ['NICKSERV_DROP']));
    }

    #[Test]
    public function deniesAccessForIrcopWithoutPermission(): void
    {
        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(5);

        $operIrcop = OperIrcop::create(1, $role, null, null);

        $rootRegistry = new RootUserRegistry(''); // No roots
        $operIrcopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $operIrcopRepo->method('findByNickId')->willReturn($operIrcop);

        // Mock roleRepository to return false for NICKSERV_DROP permission
        $roleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $roleRepo->method('hasPermission')->willReturnMap([
            [5, 'NICKSERV_DROP', false],
        ]);

        $accessHelper = new IrcopAccessHelper($rootRegistry, $operIrcopRepo, $roleRepo);

        $voter = new IrcopPermissionVoter(
            $accessHelper,
            $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class)
        );

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(1);

        $context = $this->createStub(IrcopContextInterface::class);
        $context->method('getSenderAccount')->willReturn($account);

        $token = $this->createTokenWithOperUser(true, 'testnick');

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $context, ['NICKSERV_DROP']));
    }

    #[Test]
    public function deniesAccessForIrcopWithoutAccount(): void
    {
        $rootRegistry = new RootUserRegistry(''); // No roots
        $accessHelper = new IrcopAccessHelper(
            $rootRegistry,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class)
        );

        $voter = new IrcopPermissionVoter(
            $accessHelper,
            $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class)
        );

        $context = $this->createStub(IrcopContextInterface::class);
        $context->method('getSenderAccount')->willReturn(null);

        $token = $this->createTokenWithOperUser(true, 'testnick');

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $context, ['NICKSERV_DROP']));
    }

    private function createVoterWithoutRoots(): IrcopPermissionVoter
    {
        $rootRegistry = new RootUserRegistry(''); // No roots
        $accessHelper = new IrcopAccessHelper(
            $rootRegistry,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class)
        );

        return new IrcopPermissionVoter(
            $accessHelper,
            $this->createStub(\App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface::class)
        );
    }

    private function createTokenWithOperUser(bool $isOper, string $nick = 'testnick'): TokenInterface
    {
        $senderView = new SenderView(
            uid: 'UID123',
            nick: $nick,
            ident: 'test',
            hostname: 'test.host',
            cloakedHost: 'test.cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
            isOper: $isOper,
        );

        $user = new IrcServiceUser($senderView);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
