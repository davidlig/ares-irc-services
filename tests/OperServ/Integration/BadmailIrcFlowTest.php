<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Integration;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Application\Port\In\NickAccountData;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\NickServ\Application\Port\In\UserLanguageQuery;
use App\NickServ\Application\Port\In\UserMessagePreferenceQuery;
use App\OperServ\Adapter\In\Irc\Command\BadmailCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Adapter\In\Irc\OperServService;
use App\OperServ\Adapter\Out\Persistence\Doctrine\DoctrineBadmailRepository;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\Security\OperServPermission;
use App\OperServ\Application\UseCase\Badmail\ManageBadmailHandler;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(BadmailCommand::class)]
final class BadmailIrcFlowTest extends DoctrineIntegrationTestCase
{
    /** @var list<string> */
    private array $messages = [];

    #[Test]
    public function commandMetadataAndAllPublicDispatchActions(): void
    {
        $command = $this->command();
        self::assertSame('BADMAIL', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('badmail.syntax', $command->getSyntaxKey());
        self::assertSame('badmail.help', $command->getHelpKey());
        self::assertSame('badmail.example', $command->getExampleKey());
        self::assertSame('badmail.short', $command->getShortDescKey());
        self::assertGreaterThan(0, $command->getOrder());
        self::assertTrue($command->isOperOnly());
        self::assertSame(OperServPermission::BADMAIL, $command->getRequiredPermission());
        self::assertSame([], $command->getHelpParams());
        self::assertSame(['ADD', 'DEL', 'LIST'], array_column($command->getSubCommandHelp(), 'name'));
        $service = $this->service($command);
        $sender = new SenderView('U1', 'Operator', 'i', 'host', 'cloak', '*', true, true);
        $service->dispatch('BADMAIL ADD EXAMPLE.ORG abuse reason', $sender);
        self::assertSame('example.org', $this->entityManager->getConnection()->fetchOne('SELECT domain FROM operserv_badmail'));
        foreach (['BADMAIL ADD example.org duplicate', 'BADMAIL ADD *.example.org reason', 'BADMAIL ADD other.org', 'BADMAIL LIST', 'BADMAIL LIST * bad', 'BADMAIL LIST * 1 extra', 'BADMAIL DEL 0', 'BADMAIL DEL nope', 'BADMAIL DEL 1 extra', 'BADMAIL DEL', 'BADMAIL UNKNOWN'] as $invalid) {
            $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM operserv_badmail');
            $service->dispatch($invalid, $sender);
            self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM operserv_badmail'));
        }
        $service->dispatch('BADMAIL LIST * 0', $sender);
        self::assertStringContainsString('badmail.page', implode('|', $this->messages));
        $service->dispatch('BADMAIL LIST missing* 3', $sender);
        self::assertContains('badmail.empty', $this->messages);
        $service->dispatch('BADMAIL DEL 999', $sender);
        self::assertContains('badmail.not_found', $this->messages);
        $id = $this->entityManager->getConnection()->fetchOne('SELECT id FROM operserv_badmail');
        self::assertIsInt($id);
        $service->dispatch('BADMAIL DEL ' . $id, $sender);
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM operserv_badmail'));
        $service->dispatch('BADMAIL ADD other.org reason', $sender);
        $service->dispatch('BADMAIL DEL all', $sender);
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM operserv_badmail'));
    }

    #[Test]
    public function directInvocationStillDeniesWithoutPermissionAndAuditsOnlySuccess(): void
    {
        foreach ([false, true] as $allowed) {
            $command = $this->command($allowed);
            $command->execute($this->context($command, ['ADD', 'example.org', 'reason']));
            self::assertSame($allowed ? 1 : 0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM operserv_badmail'));
            self::assertCount($allowed ? 1 : 0, $this->auditRecords);
            if ($allowed) {
                self::assertSame('example.org', $this->auditRecords[0]->target);
                self::assertSame('operserv', $this->auditRecords[0]->service);
                self::assertSame('operserv.badmail', $this->auditRecords[0]->permission);
                self::assertSame('reason', $this->auditRecords[0]->reason);
            }
        }
        $command = $this->command();
        $command->execute($this->context($command, [], true));
        $command->execute($this->context($command, ['ADD', 'other.org', ' ']));
        self::assertSame([], $this->auditRecords);
    }

    private bool $allowed = true;

    /** @var list<CommandAuditRecord> */
    private array $auditRecords = [];

    private function command(bool $allowed = true): BadmailCommand
    {
        $this->allowed = $allowed;
        $this->auditRecords = [];
        $audit = $this->createStub(CommandAuditRecorder::class);
        $audit->method('record')->willReturnCallback(function (CommandAuditRecord $record): void { $this->auditRecords[] = $record; });

        return new BadmailCommand(new ManageBadmailHandler($audit, new DoctrineBadmailRepository($this->entityManager), 1));
    }

    private function authorization(): OperatorAuthorizationQuery
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('permission')->willReturn($this->allowed ? AuthorizationDecision::grantedBy(AuthorizationGrant::RolePermission) : AuthorizationDecision::denied());

        return $authorization;
    }

    /** @param list<string> $args */
    private function context(BadmailCommand $command, array $args, bool $noSender = false): OperServContext
    {
        return new OperServContext($noSender ? null : new SenderView('U1', 'Operator', 'i', 'h', 'c', '*', true, true), new NickAccountData(42, 'Operator', 'en'), 'BADMAIL', $args, $this->notifier(), $this->translator(), 'en', 'UTC', 'NOTICE', new OperServCommandRegistry([$command]), new ServiceNicknameRegistry([]), $this->authorization());
    }

    private function notifier(): OperServNotifierInterface
    {
        $notifier = $this->createStub(OperServNotifierInterface::class);
        $notifier->method('sendMessage')->willReturnCallback(function (string $target, string $message): void { $this->messages[] = $message; });

        return $notifier;
    }

    private function translator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $key): string => $key);

        return $translator;
    }

    private function service(BadmailCommand $command): OperServService
    {
        $accounts = $this->createStub(NickAccountQuery::class);
        $accounts->method('findAccountByNick')->willReturn(new NickAccountData(42, 'Operator', 'en'));
        $languages = $this->createStub(UserLanguageQuery::class);
        $languages->method('resolveFromAccount')->willReturn('en');

        return new OperServService(new OperServCommandRegistry([$command]), $accounts, $languages, $this->createStub(UserMessagePreferenceQuery::class), $this->notifier(), $this->translator(), new ServiceNicknameRegistry([]), $this->authorization());
    }
}
