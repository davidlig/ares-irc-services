<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\In\Irc\NickServCommandRegistry;
use App\NickServ\Adapter\Out\OperServ\OperServEmailDomainPolicy;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Adapter\In\Irc\Command\BadmailCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServHelpFormatterContextAdapter;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\EmailDomainPolicy;
use App\OperServ\Application\Port\In\OperatorPermissionCatalog;
use App\OperServ\Application\Port\Out\BadmailRepository;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Port\Out\RootIdentityRegistry;
use App\OperServ\Application\Security\OperatorAuthorizationService;
use App\OperServ\Application\Security\OperatorPermissionPolicy;
use App\OperServ\Application\Security\OperServPermission;
use App\OperServ\Application\Security\RootAuthorizationPolicy;
use App\OperServ\Application\UseCase\Badmail\BadmailEmailPolicy;
use App\OperServ\Application\UseCase\Badmail\ManageBadmailHandler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversNothing]
final class OperServBadmailWiringTest extends KernelTestCase
{
    #[Test]
    public function runtimeRegistryPublishedPermissionAndConsumerBridgesBelongToOperServ(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $operserv = $container->get(OperServCommandRegistry::class);
        self::assertInstanceOf(OperServCommandRegistry::class, $operserv);
        self::assertInstanceOf(BadmailCommand::class, $operserv->find('BADMAIL'));
        $nickserv = $container->get(NickServCommandRegistry::class);
        self::assertInstanceOf(NickServCommandRegistry::class, $nickserv);
        self::assertNull($nickserv->find('BADMAIL'));
        $catalog = $container->get(OperatorPermissionCatalog::class);
        self::assertInstanceOf(OperatorPermissionCatalog::class, $catalog);
        self::assertContains(OperServPermission::BADMAIL, $catalog->getAllPermissions());
        self::assertNotContains('nickserv.badmail', $catalog->getAllPermissions());
        self::assertSame(50, $container->getParameter('operserv.badmail_list_page_size_default'));
        self::assertSame(50, $container->getParameter('operserv.badmail_list_page_size'));
        self::assertInstanceOf(BadmailEmailPolicy::class, $container->get(EmailDomainPolicy::class));
        self::assertInstanceOf(OperServEmailDomainPolicy::class, $container->get(\App\NickServ\Application\Port\Out\EmailDomainPolicy::class));
        self::assertInstanceOf(\App\ChanServ\Adapter\Out\OperServ\OperServEmailDomainPolicy::class, $container->get(\App\ChanServ\Application\Port\Out\EmailDomainPolicy::class));
    }

    #[Test]
    public function roleDenialGrantAndRootUseSameExecutionAndGeneralHelpPolicy(): void
    {
        foreach ([[false, false, true], [true, false, true], [false, true, false], [true, false, false]] as [$roleGrant, $root, $ircOperator]) {
            $allowed = $root || ($roleGrant && $ircOperator);
            $roles = $this->createStub(OperatorRoleAccess::class);
            $roles->method('hasAssignedRole')->willReturn($roleGrant);
            $roles->method('hasPermission')->willReturnCallback(static fn (int $id, string $permission): bool => $roleGrant && OperServPermission::BADMAIL === $permission);
            $roots = $this->createStub(RootIdentityRegistry::class);
            $roots->method('contains')->willReturn($root);
            $rootPolicy = new RootAuthorizationPolicy($roots);
            $authorization = new OperatorAuthorizationService($rootPolicy, new OperatorPermissionPolicy($rootPolicy, $roles), $roles);
            $repository = $this->createMock(BadmailRepository::class);
            $repository->expects($allowed ? self::once() : self::never())->method('countMatching')->willReturn(0);
            $command = new BadmailCommand(new ManageBadmailHandler($this->createStub(CommandAuditRecorder::class), $repository, 50));
            $messages = [];
            $notifier = $this->createStub(OperServNotifierInterface::class);
            $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void { $messages[] = $message; });
            $translator = $this->createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);
            $context = new OperServContext(new SenderView('U1', 'Operator', 'ident', 'host', 'cloak', '*', true, $ircOperator), new NickAccountData(42, 'Operator', 'en'), 'BADMAIL', ['LIST', '*'], $notifier, $translator, 'en', 'UTC', 'NOTICE', new OperServCommandRegistry([$command]), new ServiceNicknameRegistry([]), $authorization);
            $command->execute($context);
            self::assertSame($allowed ? ['badmail.header', 'badmail.empty', 'badmail.page'] : ['error.permission_denied'], $messages);
            $help = new OperServHelpFormatterContextAdapter($context);
            self::assertSame($allowed, $help->shouldShowCommandInGeneralHelp($command));
            self::assertSame($allowed, $help->canViewCommandInHelp($command));
        }
    }
}
