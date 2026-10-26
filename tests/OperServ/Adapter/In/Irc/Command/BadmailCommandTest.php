<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Adapter\In\Irc\Command\BadmailCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\Port\Out\BadmailRepository;
use App\OperServ\Application\UseCase\Badmail\ManageBadmailHandler;
use App\OperServ\Domain\Entity\BadmailEntry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(BadmailCommand::class)]
final class BadmailCommandTest extends TestCase
{
    /** @return iterable<string, array{list<string>, string}> */
    public static function invalidArguments(): iterable
    {
        yield 'unknown action' => [['UNKNOWN'], 'badmail.syntax'];
        yield 'blank list pattern' => [['LIST', ' '], 'badmail.list.syntax'];
        yield 'missing free-text reason' => [['ADD', 'example.com'], 'badmail.add.syntax'];
        yield 'extra delete operand' => [['DEL', '5', 'ignored'], 'badmail.del.syntax'];
        yield 'zero remains a syntax error' => [['DEL', '0'], 'badmail.del.syntax'];
        yield 'invalid page remains contextual syntax' => [['LIST', '*@example.com', 'page'], 'badmail.list.syntax'];
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('invalidArguments')]
    public function rejectsInvalidStructureWithoutRepositoryOrAuditEffects(array $arguments, string $syntaxKey): void
    {
        $repository = $this->createMock(BadmailRepository::class);
        $repository->expects(self::never())->method('add');
        $repository->expects(self::never())->method('delete');
        $repository->expects(self::never())->method('clear');
        $repository->expects(self::never())->method('countMatching');
        $repository->expects(self::never())->method('page');
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $notifier = new RecordingBadmailNotifier();

        $this->command($audit, $repository)->execute($this->context($arguments, $notifier));

        self::assertSame(['error.syntax'], $notifier->messages);
        self::assertSame($syntaxKey, $notifier->translations->parameters['error.syntax']['%syntax%']);
    }

    #[Test]
    public function preservesLeadingZeroPageAsContextualSyntax(): void
    {
        $repository = $this->createMock(BadmailRepository::class);
        $repository->expects(self::never())->method('countMatching');
        $repository->expects(self::never())->method('page');
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $notifier = new RecordingBadmailNotifier();

        $this->command($audit, $repository)->execute($this->context(['LIST', '*@example.com', '05'], $notifier));

        self::assertSame(['error.syntax'], $notifier->messages);
        self::assertSame('badmail.list.syntax', $notifier->translations->parameters['error.syntax']['%syntax%']);
    }

    #[Test]
    public function preservesDeleteAllAndOptionalListPage(): void
    {
        $repository = $this->createMock(BadmailRepository::class);
        $repository->expects(self::once())->method('clear')->willReturn(0);
        $repository->expects(self::once())->method('countMatching')->with('*@example.com')->willReturn(2);
        $repository->expects(self::once())->method('page')->with('*@example.com', 1, 1)->willReturn([]);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::exactly(2))->method('record')->with(self::isInstanceOf(CommandAuditRecord::class));
        $command = $this->command($audit, $repository, pageSize: 1);
        $deleteNotifier = new RecordingBadmailNotifier();
        $listNotifier = new RecordingBadmailNotifier();

        $command->execute($this->context(['DEL', 'ALL'], $deleteNotifier));
        $command->execute($this->context(['LIST', '*@example.com', '2'], $listNotifier));

        self::assertSame(['badmail.cleared'], $deleteNotifier->messages);
        self::assertSame(['badmail.header', 'badmail.page'], $listNotifier->messages);
        self::assertSame(2, $listNotifier->translations->parameters['badmail.page']['%page%']);
    }

    #[Test]
    #[DataProvider('filterValidateIntPageForms')]
    public function preservesFilterValidateIntPageForms(string $pageToken, int $expectedPage): void
    {
        $repository = $this->createMock(BadmailRepository::class);
        $repository->expects(self::once())->method('countMatching')->with('*@example.com')->willReturn(10);
        $repository->expects(self::once())->method('page')->with('*@example.com', $expectedPage - 1, 1)->willReturn([]);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::once())->method('record');
        $notifier = new RecordingBadmailNotifier();

        $this->command($audit, $repository, pageSize: 1)->execute($this->context(['LIST', '*@example.com', $pageToken], $notifier));

        self::assertSame(['badmail.header', 'badmail.page'], $notifier->messages);
        self::assertSame($expectedPage, $notifier->translations->parameters['badmail.page']['%page%']);
    }

    /** @return iterable<string, array{string, int}> */
    public static function filterValidateIntPageForms(): iterable
    {
        yield 'leading plus is accepted' => ['+5', 5];
        yield 'negative page remains clamped to first page' => ['-1', 1];
    }

    #[Test]
    public function preservesMultiwordAddReason(): void
    {
        $repository = $this->createMock(BadmailRepository::class);
        $repository->expects(self::once())->method('add')->with(self::callback(
            static fn (BadmailEntry $entry): bool => 'example.com' === $entry->getDomain()
                && 'policy violation' === $entry->getReason(),
        ))->willReturn(true);
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::once())->method('record');
        $notifier = new RecordingBadmailNotifier();

        $this->command($audit, $repository)->execute($this->context(['ADD', 'example.com', 'policy', 'violation'], $notifier));

        self::assertSame(['badmail.added'], $notifier->messages);
    }

    private function command(CommandAuditRecorder $audit, BadmailRepository $repository, int $pageSize = 50): BadmailCommand
    {
        return new BadmailCommand(new ManageBadmailHandler($audit, $repository, $pageSize));
    }

    /** @param list<string> $arguments */
    private function context(array $arguments, RecordingBadmailNotifier $notifier): OperServContext
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('permission')->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RootIdentity));

        return new OperServContext(
            new SenderView('001AAA', 'Oper', 'ident', 'host', 'cloak', 'ip', true, true),
            new NickAccountData(42, 'Oper', 'en'),
            'BADMAIL',
            $arguments,
            $notifier,
            $notifier->translations,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $authorization,
        );
    }
}

final class RecordingBadmailNotifier implements OperServNotifierInterface
{
    /** @var list<string> */
    public array $messages = [];

    public RecordingBadmailTranslation $translations;

    public function __construct()
    {
        $this->translations = new RecordingBadmailTranslation();
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->messages[] = $message;
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        $this->messages[] = $message;
    }

    public function getNick(): string
    {
        return 'OperServ';
    }

    public function getUid(): string
    {
        return '001OS';
    }

    public function getServiceKey(): string
    {
        return 'operserv';
    }
}

final class RecordingBadmailTranslation implements TranslatorInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $parameters = [];

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->parameters[$id] = $parameters;

        return $id;
    }

    public function getLocale(): string
    {
        return 'en';
    }
}
