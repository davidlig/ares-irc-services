<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Security\Voter;

use App\Application\NickServ\Command\NickServCommandRegistry;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\OperServ\Entity\OperIrcop;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use App\Infrastructure\NickServ\Security\Voter\NickServSetVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;

#[CoversClass(NickServSetVoter::class)]
final class NickServSetVoterTest extends TestCase
{
    #[Test]
    public function supportsSetPermissionWithContext(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createContext('TestUser', false, true);

        self::assertTrue(VoterInterface::ACCESS_ABSTAIN !== $voter->vote($this->createTokenWithUser(false, true, 'TestUser'), $context, [NickServPermission::SET]));
    }

    #[Test]
    public function abstainsForOtherPermission(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createContext('TestUser', false, true);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->createTokenWithUser(false, true, 'TestUser'), $context, ['OTHER_PERMISSION']));
    }

    #[Test]
    public function abstainsForNonContextSubject(): void
    {
        $voter = $this->createVoterWithoutRoots();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->createTokenWithUser(false, true, 'TestUser'), new stdClass(), [NickServPermission::SET]));
    }

    #[Test]
    public function deniesAccessForNonIrcServiceUser(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createContext('TestUser', false, true);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $context, [NickServPermission::SET]));
    }

    #[Test]
    public function ownerIdentifiedGrantsAccess(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createContext('TestUser', false, true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->createTokenWithUser(false, true, 'TestUser'), $context, [NickServPermission::SET]));
    }

    #[Test]
    public function ownerNotIdentifiedDeniesAccess(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createContext('TestUser', false, false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->createTokenWithUser(false, false, 'TestUser'), $context, [NickServPermission::SET]));
    }

    #[Test]
    public function rootUserGrantsAccess(): void
    {
        $voter = $this->createVoterWithRoots('RootUser');
        $context = $this->createContext('OtherAccount', false, true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->createTokenWithUser(false, true, 'RootUser'), $context, [NickServPermission::SET]));
    }

    #[Test]
    public function ircopWithPermissionGrantsAccess(): void
    {
        $voter = $this->createVoterWithPermissions('OperUser', [NickServPermission::SET]);
        $context = $this->createContext('OperAccount', false, true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->createTokenWithUser(true, true, 'OperUser'), $context, [NickServPermission::SET]));
    }

    #[Test]
    public function ircopWithoutPermissionDeniesAccess(): void
    {
        $voter = $this->createVoterWithPermissions('OperUser', []);
        $context = $this->createContext('OperAccount', false, true);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->createTokenWithUser(true, true, 'OperUser'), $context, [NickServPermission::SET]));
    }

    #[Test]
    public function userWithoutOperRoleDeniesAccess(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createContext('NormalAccount', false, true);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->createTokenWithUser(false, true, 'NormalUser'), $context, [NickServPermission::SET]));
    }

    #[Test]
    public function deniesAccessForIrcopWithoutAccount(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createContextWithoutAccount('TestUser', true);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->createTokenWithUser(true, true, 'TestUser'), $context, [NickServPermission::SET]));
    }

    #[Test]
    public function ownerIdentifiedWithDifferentNicknameCasedGrantsAccess(): void
    {
        $voter = $this->createVoterWithoutRoots();
        $context = $this->createContext('TestAccount', false, true);

        $token = $this->createTokenWithUser(false, true, 'testaccount');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $context, [NickServPermission::SET]));
    }

    private function createVoterWithoutRoots(): NickServSetVoter
    {
        $rootRegistry = new RootUserRegistry('');
        $accessHelper = new IrcopAccessHelper(
            $rootRegistry,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class)
        );

        return new NickServSetVoter(
            $accessHelper,
            $rootRegistry,
            $this->createStub(OperIrcopRepositoryInterface::class)
        );
    }

    private function createVoterWithRoots(string $rootNick): NickServSetVoter
    {
        $rootRegistry = new RootUserRegistry($rootNick);
        $accessHelper = new IrcopAccessHelper(
            $rootRegistry,
            $this->createStub(OperIrcopRepositoryInterface::class),
            $this->createStub(OperRoleRepositoryInterface::class)
        );

        return new NickServSetVoter(
            $accessHelper,
            $rootRegistry,
            $this->createStub(OperIrcopRepositoryInterface::class)
        );
    }

    private function createVoterWithPermissions(string $nick, array $permissions): NickServSetVoter
    {
        $rootRegistry = new RootUserRegistry('');

        $role = $this->createStub(OperRole::class);
        $role->method('getId')->willReturn(1);

        $ircop = $this->createStub(OperIrcop::class);
        $ircop->method('getRole')->willReturn($role);

        $operIrcopRepo = $this->createStub(OperIrcopRepositoryInterface::class);
        $operIrcopRepo->method('findByNickId')->willReturn($ircop);

        $operRoleRepo = $this->createStub(OperRoleRepositoryInterface::class);
        $operRoleRepo->method('hasPermission')->willReturnCallback(static fn (int $roleId, string $perm): bool => in_array($perm, $permissions, true));

        $accessHelper = new IrcopAccessHelper(
            $rootRegistry,
            $operIrcopRepo,
            $operRoleRepo
        );

        return new NickServSetVoter(
            $accessHelper,
            $rootRegistry,
            $operIrcopRepo
        );
    }

    private function createContext(string $nickname, bool $isOper, bool $isIdentified): NickServContext
    {
        $sender = $this->createSenderView($isOper, $isIdentified, $nickname);

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn($nickname);
        $account->method('getId')->willReturn(1);

        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            $sender,
            $account,
            'SET',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createContextWithoutAccount(string $nick, bool $isOper): NickServContext
    {
        $sender = $this->createSenderView($isOper, true, $nick);

        $notifier = $this->createStub(NickServNotifierInterface::class);
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new NickServContext(
            $sender,
            null,
            'SET',
            [],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new NickServCommandRegistry([]),
            new PendingVerificationRegistry(),
            new RecoveryTokenRegistry(),
            $this->createServiceNicks(),
        );
    }

    private function createSenderView(bool $isOper, bool $isIdentified, string $nick): SenderView
    {
        return new SenderView(
            uid: 'UID123',
            nick: $nick,
            ident: 'test',
            hostname: 'test.host',
            cloakedHost: 'test.cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: $isIdentified,
            isOper: $isOper,
        );
    }

    private function createTokenWithUser(bool $isOper, bool $isIdentified, string $nick): TokenInterface
    {
        $senderView = $this->createSenderView($isOper, $isIdentified, $nick);
        $user = new IrcServiceUser($senderView);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    private function createServiceNicks(): \App\Application\ApplicationPort\ServiceNicknameRegistry
    {
        $provider = $this->createStub(\App\Application\ApplicationPort\ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('nickserv');
        $provider->method('getNickname')->willReturn('NickServ');

        return new \App\Application\ApplicationPort\ServiceNicknameRegistry([$provider]);
    }
}
