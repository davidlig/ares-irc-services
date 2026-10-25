<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Integration;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Translation\Translator;
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

    #[Test]
    #[DataProvider('locales')]
    public function malformedArgumentsPresentLocalizedSubcommandSyntaxWithoutMutation(string $locale): void
    {
        $translator = $this->catalogTranslator($locale);
        $service = $this->service($this->command(), $translator, $locale);
        $sender = new SenderView('U1', 'Operator', 'i', 'host', 'cloak', '*', true, true);
        $service->dispatch('BADMAIL ADD example.org Stored multiword reason.', $sender);
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM operserv_badmail');
        $auditBefore = $this->auditRecords;

        foreach ([
            'BADMAIL' => 'badmail.syntax',
            'BADMAIL UNKNOWN' => 'badmail.syntax',
            'BADMAIL ADD' => 'badmail.add.syntax',
            'BADMAIL add other.org' => 'badmail.add.syntax',
            'BADMAIL DEL' => 'badmail.del.syntax',
            'BADMAIL DEL 0' => 'badmail.del.syntax',
            'BADMAIL DEL -1' => 'badmail.del.syntax',
            'BADMAIL DEL nope' => 'badmail.del.syntax',
            'BADMAIL DEL 1 extra' => 'badmail.del.syntax',
            'BADMAIL DEL ALL extra' => 'badmail.del.syntax',
            'BADMAIL LIST' => 'badmail.list.syntax',
            'BADMAIL LIST * bad' => 'badmail.list.syntax',
            'BADMAIL LIST * 1.5' => 'badmail.list.syntax',
            'BADMAIL LIST example.org extra text' => 'badmail.list.syntax',
        ] as $input => $syntaxKey) {
            $this->messages = [];
            $service->dispatch($input, $sender);
            $syntax = $translator->trans($syntaxKey, [], 'operserv');
            self::assertSame([$translator->trans('error.syntax', ['%syntax%' => $syntax], 'common')], $this->messages, $locale . ': ' . $input);
            self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM operserv_badmail'));
            self::assertSame($auditBefore, $this->auditRecords);
        }

        foreach ([
            'BADMAIL ADD example.org duplicate reason' => 'badmail.duplicate',
            'BADMAIL ADD *.example.org reason' => 'badmail.invalid_domain',
            "BADMAIL ADD other.org invalid\x02reason" => 'badmail.invalid_reason',
        ] as $input => $errorKey) {
            $this->messages = [];
            $service->dispatch($input, $sender);
            self::assertSame([$translator->trans($errorKey, [], 'operserv')], $this->messages);
            self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM operserv_badmail'));
            self::assertSame($auditBefore, $this->auditRecords);
        }
    }

    #[Test]
    #[DataProvider('confirmations')]
    public function successRepliesPreserveLocalizedWordingAndTerminateIrcFormatting(string $locale, string $added, string $deleted, string $cleared): void
    {
        $translator = $this->catalogTranslator($locale);
        $service = $this->service($this->command(), $translator, $locale);
        $sender = new SenderView('U1', 'Operator', 'i', 'host', 'cloak', '*', true, true);
        $reason = 'Stored multiword reason.';
        $service->dispatch('BADMAIL ADD EXAMPLE.ORG ' . $reason, $sender);
        self::assertSame(["\x0303✓\x03 " . str_replace('%domain%', "\x02example.org\x02", $added)], $this->messages);
        $entry = $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM operserv_badmail');
        self::assertIsArray($entry);
        self::assertSame('example.org', $entry['domain']);
        self::assertSame($reason, $entry['reason']);
        $id = $entry['id'];
        self::assertIsInt($id);

        foreach (['BADMAIL LIST example.org', 'BADMAIL LIST example.org 0', 'BADMAIL LIST example.org -1'] as $input) {
            $this->messages = [];
            $service->dispatch($input, $sender);
            self::assertSame([
                $translator->trans('badmail.header', [], 'operserv'),
                $id . ' | example.org | ' . $reason,
                $translator->trans('badmail.page', ['%page%' => 1, '%pages%' => 1, '%total%' => 1], 'operserv'),
            ], $this->messages);
            self::assertSame($entry, $this->entityManager->getConnection()->fetchAssociative('SELECT * FROM operserv_badmail'));
        }

        $this->messages = [];
        $service->dispatch('BADMAIL DEL ' . $id, $sender);
        self::assertSame(["\x0303✓\x03 " . str_replace('%id%', "\x02" . $id . "\x02", $deleted)], $this->messages);
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM operserv_badmail'));
        $service->dispatch('BADMAIL ADD other.org Another reason', $sender);
        $service->dispatch('BADMAIL ADD third.org Third reason', $sender);
        $this->messages = [];
        $service->dispatch('BADMAIL DEL ALL', $sender);
        self::assertSame(["\x0303✓\x03 " . str_replace('%total%', "\x022\x02", $cleared)], $this->messages);
        self::assertSame(0, $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM operserv_badmail'));
        self::assertCount(8, $this->auditRecords);
        self::assertSame($reason, $this->auditRecords[0]->reason);
    }

    #[Test]
    #[DataProvider('reasonTerms')]
    public function reasonTerminologyIsConsistentAcrossPresentation(string $locale, string $term): void
    {
        $translator = $this->catalogTranslator($locale);
        foreach (['badmail.add.syntax', 'badmail.add.help', 'badmail.invalid_reason', 'badmail.header'] as $key) {
            self::assertStringContainsString($term, mb_strtolower($translator->trans($key, [], 'operserv')), $locale . ': ' . $key);
        }
        if ('en' === $locale) {
            self::assertSame('ID | Domain | Reason', $translator->trans('badmail.header', [], 'operserv'));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        foreach (['ca', 'de', 'el', 'en', 'es', 'eu', 'fr', 'gl', 'it', 'nl', 'pl', 'pt', 'ro', 'tr'] as $locale) {
            yield $locale => [$locale];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function reasonTerms(): iterable
    {
        foreach (['ca' => 'motiu', 'de' => 'begründung', 'el' => 'αιτιολογία', 'en' => 'reason', 'es' => 'razón', 'eu' => 'arrazoi', 'fr' => 'raison', 'gl' => 'motivo', 'it' => 'motivo', 'nl' => 'reden', 'pl' => 'powód', 'pt' => 'motivo', 'ro' => 'motiv', 'tr' => 'gerekçe'] as $locale => $term) {
            yield $locale => [$locale, $term];
        }
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function confirmations(): iterable
    {
        yield 'ca' => ['ca', 'Domini %domain% bloquejat.', 'Domini bloquejat %id% eliminat.', 'S’han eliminat %total% dominis bloquejats.'];
        yield 'de' => ['de', 'Domain %domain% gesperrt.', 'Domainsperre %id% entfernt.', '%total% Domainsperren entfernt.'];
        yield 'el' => ['el', 'Ο τομέας %domain% αποκλείστηκε.', 'Ο αποκλεισμός %id% αφαιρέθηκε.', 'Αφαιρέθηκαν %total% αποκλεισμένοι τομείς.'];
        yield 'en' => ['en', 'Blocked domain %domain%.', 'Removed blocked domain %id%.', 'Removed %total% blocked domains.'];
        yield 'es' => ['es', 'Dominio %domain% bloqueado.', 'Dominio bloqueado %id% eliminado.', 'Se han eliminado %total% dominios bloqueados.'];
        yield 'eu' => ['eu', '%domain% domeinua blokeatu da.', '%id% domeinu-blokeoa kendu da.', '%total% domeinu-blokeo kendu dira.'];
        yield 'fr' => ['fr', 'Domaine %domain% bloqué.', 'Blocage %id% supprimé.', '%total% domaines bloqués supprimés.'];
        yield 'gl' => ['gl', 'Dominio %domain% bloqueado.', 'Dominio bloqueado %id% eliminado.', 'Elimináronse %total% dominios bloqueados.'];
        yield 'it' => ['it', 'Dominio %domain% bloccato.', 'Blocco %id% rimosso.', 'Rimossi %total% domini bloccati.'];
        yield 'nl' => ['nl', 'Domein %domain% geblokkeerd.', 'Domeinblokkade %id% verwijderd.', '%total% domeinblokkades verwijderd.'];
        yield 'pl' => ['pl', 'Zablokowano domenę %domain%.', 'Usunięto blokadę %id%.', 'Usunięto %total% zablokowanych domen.'];
        yield 'pt' => ['pt', 'Domínio %domain% bloqueado.', 'Bloqueio %id% removido.', 'Foram removidos %total% domínios bloqueados.'];
        yield 'ro' => ['ro', 'Domeniul %domain% a fost blocat.', 'Blocarea %id% a fost eliminată.', 'Au fost eliminate %total% domenii blocate.'];
        yield 'tr' => ['tr', '%domain% alan adı engellendi.', '%id% alan adı engeli kaldırıldı.', '%total% alan adı engeli kaldırıldı.'];
    }

    private function catalogTranslator(string $locale): Translator
    {
        $translator = new Translator($locale);
        $translator->addLoader('irc_yaml', new IrcYamlTranslationLoader());
        foreach (['common', 'operserv'] as $domain) {
            $translator->addResource('irc_yaml', __DIR__ . '/../../../translations/' . $domain . '.' . $locale . '.yaml', $locale, $domain);
        }

        return $translator;
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

    private function service(BadmailCommand $command, ?TranslatorInterface $translator = null, string $locale = 'en'): OperServService
    {
        $accounts = $this->createStub(NickAccountQuery::class);
        $accounts->method('findAccountByNick')->willReturn(new NickAccountData(42, 'Operator', 'en'));
        $languages = $this->createStub(UserLanguageQuery::class);
        $languages->method('resolveFromAccount')->willReturn($locale);

        return new OperServService(new OperServCommandRegistry([$command]), $accounts, $languages, $this->createStub(UserMessagePreferenceQuery::class), $this->notifier(), $translator ?? $this->translator(), new ServiceNicknameRegistry([]), $this->authorization());
    }
}
