<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\Command\RegmodeCommand;
use App\NickServ\Adapter\In\Irc\HelpFormatterContextAdapter;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Adapter\Out\Security\OperNickServOperatorAccess;
use App\NickServ\Application\Port\Out\RegistrationPolicyRepository;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\UseCase\RegistrationMode\RegistrationModeHandler;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\NickServ\Domain\ValueObject\RegistrationMode;
use App\OperServ\Application\IrcopAccessQueryService;
use App\OperServ\Application\Port\In\OperatorPermissionCatalog;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use App\OperServ\Application\Security\OperatorAuthorizationService;
use App\OperServ\Application\Security\OperatorPermissionPolicy;
use App\OperServ\Application\Security\RootAuthorizationPolicy;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversNothing]
final class NickServRegmodeWiringTest extends KernelTestCase
{
    public function testRuntimeRegistryAndPublishedPermission(): void
    {
        self::bootKernel();
        $commands = self::getContainer()->get(NickServCommandRegistry::class);
        self::assertInstanceOf(NickServCommandRegistry::class, $commands);
        self::assertInstanceOf(RegmodeCommand::class, $commands->find('REGMODE'));
        $permissions = self::getContainer()->get(OperatorPermissionCatalog::class);
        self::assertInstanceOf(OperatorPermissionCatalog::class, $permissions);
        self::assertContains(NickServPermission::REGMODE, $permissions->getAllPermissions());
    }

    public function testRoleDenialGrantAndRootUseSameCommandAndHelpPolicy(): void
    {
        foreach ([[false, false], [true, false], [false, true]] as [$roleGrant, $root]) {
            $roles = $this->createStub(OperatorRoleAccess::class);
            $roles->method('hasAssignedRole')->willReturn($roleGrant);
            $roles->method('hasPermission')->willReturnCallback(static fn (int $id, string $permission): bool => $roleGrant && NickServPermission::REGMODE === $permission);
            $roots = $this->createStub(RootIdentityRegistry::class);
            $roots->method('contains')->willReturn($root);
            $rootPolicy = new RootAuthorizationPolicy($roots);
            $access = new OperNickServOperatorAccess(new IrcopAccessQueryService(new OperatorAuthorizationService(
                $rootPolicy,
                new OperatorPermissionPolicy($rootPolicy, $roles),
                $roles,
            )));
            $policy = $this->createMock(RegistrationPolicyRepository::class);
            $policy->expects(self::never())->method('change');
            $policy->expects($roleGrant || $root ? self::once() : self::never())->method('current')->willReturn(RegistrationMode::Token);
            $command = new RegmodeCommand(new RegistrationModeHandler($policy), $access);
            $account = $this->createStub(RegisteredNick::class);
            $account->method('getId')->willReturn(42);
            $notifier = $this->createStub(NickServNotifierInterface::class);
            $translator = $this->createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);
            $context = new NickServContext(
                new SenderView('U1', 'Operator', 'ident', 'host', 'cloak', '*', true, !$root, 'SID', 'host', 'o'),
                $account,
                'REGMODE',
                ['STATUS'],
                $notifier,
                $translator,
                'en',
                'UTC',
                'NOTICE',
                new NickServCommandRegistry([$command]),
                new PendingVerificationRegistry(),
                new RecoveryTokenRegistry(),
                new ServiceNicknameRegistry([]),
            );
            self::assertSame($roleGrant || $root, $command->execute($context)->success);
            $help = new HelpFormatterContextAdapter($context, $access);
            self::assertFalse($help->shouldShowCommandInGeneralHelp($command));
            self::assertSame($roleGrant || $root, $help->canViewCommandInHelp($command));
        }
    }
}
