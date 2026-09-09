<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Adapter\In\Irc\Command\IrcopCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\UseCase\ManageIrcop\IrcopAction;
use App\OperServ\Application\UseCase\ManageIrcop\IrcopListEntry;
use App\OperServ\Application\UseCase\ManageIrcop\IrcopOutcome;
use App\OperServ\Application\UseCase\ManageIrcop\ManageIrcop;
use App\OperServ\Application\UseCase\ManageIrcop\ManageIrcopHandlerInterface;
use App\OperServ\Application\UseCase\ManageIrcop\ManageIrcopResult;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(IrcopCommand::class)]
#[CoversClass(ManageIrcop::class)]
#[CoversClass(ManageIrcopResult::class)]
#[CoversClass(IrcopListEntry::class)]
final class IrcopCommandTest extends TestCase
{
    #[Test]
    public function exposesRootOnlyCommandMetadata(): void
    {
        $command = new IrcopCommand($this->createStub(ManageIrcopHandlerInterface::class));

        self::assertSame('IRCOP', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame('ircop.syntax', $command->getSyntaxKey());
        self::assertSame('ircop.help', $command->getHelpKey());
        self::assertSame(1, $command->getOrder());
        self::assertSame('ircop.short', $command->getShortDescKey());
        self::assertTrue($command->isOperOnly());
        self::assertSame(OperatorAuthorizationAttribute::ROOT, $command->getRequiredPermission());
        self::assertSame(['ADD', 'DEL', 'LIST'], array_column($command->getSubCommandHelp(), 'name'));
    }

    #[Test]
    public function mapsActorAccountAndTimestampAndPresentsRoleChange(): void
    {
        $received = null;
        $handler = $this->createMock(ManageIrcopHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->willReturnCallback(static function (ManageIrcop $input) use (&$received): ManageIrcopResult {
                $received = $input;

                return new ManageIrcopResult(IrcopOutcome::RoleChanged, 'Target', 'ADMIN', 'OPER');
            });
        $translation = new IrcopCommandTranslation();
        $before = new DateTimeImmutable();

        new IrcopCommand($handler)->execute($this->context(['ADD', 'Target', 'admin'], $translation));

        $after = new DateTimeImmutable();
        self::assertInstanceOf(ManageIrcop::class, $received);
        self::assertSame(IrcopAction::Add, $received->action);
        self::assertSame('RootOper', $received->actorNickname);
        self::assertSame(42, $received->actorAccountId);
        self::assertSame('Target', $received->nickname);
        self::assertSame('ADMIN', $received->roleName);
        self::assertGreaterThanOrEqual((float) $before->format('U.u'), (float) $received->occurredAt->format('U.u'));
        self::assertLessThanOrEqual((float) $after->format('U.u'), (float) $received->occurredAt->format('U.u'));
        self::assertSame('ircop.role_changed', $translation->lastKey);
        self::assertSame('Target', $translation->lastParameters['%nickname%']);
        self::assertSame('OPER', $translation->lastParameters['%old%']);
        self::assertSame('ADMIN', $translation->lastParameters['%new%']);
    }

    #[Test]
    public function preservesLegacyDeleteSyntaxAndPresentsDeletion(): void
    {
        $handler = $this->createMock(ManageIrcopHandlerInterface::class);
        $handler->expects(self::once())
            ->method('handle')
            ->with(self::callback(static fn (ManageIrcop $input): bool => IrcopAction::Delete === $input->action
                && 'Target' === $input->nickname
                && '' === $input->roleName))
            ->willReturn(new ManageIrcopResult(IrcopOutcome::Deleted, 'Target'));
        $translation = new IrcopCommandTranslation();

        new IrcopCommand($handler)->execute($this->context(['Target', 'DEL'], $translation));

        self::assertSame('ircop.del.done', $translation->lastKey);
        self::assertSame('Target', $translation->lastParameters['%nickname%']);
    }

    #[Test]
    public function missingSenderDoesNotReachTheUseCase(): void
    {
        $handler = $this->createMock(ManageIrcopHandlerInterface::class);
        $handler->expects(self::never())->method('handle');

        new IrcopCommand($handler)->execute($this->context(['LIST'], new IrcopCommandTranslation(), true));
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('actionSyntaxes')]
    public function mapsModernLegacyAndUnknownActions(array $arguments, IrcopAction $expectedAction, string $expectedNick, string $expectedRole): void
    {
        $handler = $this->createMock(ManageIrcopHandlerInterface::class);
        $handler->expects(self::once())->method('handle')->with(self::callback(
            static fn (ManageIrcop $input): bool => $expectedAction === $input->action
                && $expectedNick === $input->nickname
                && $expectedRole === $input->roleName,
        ))->willReturn(new ManageIrcopResult(IrcopOutcome::UnknownAction));

        new IrcopCommand($handler)->execute($this->context($arguments, new IrcopCommandTranslation()));
    }

    /** @return iterable<string, array{list<string>, IrcopAction, string, string}> */
    public static function actionSyntaxes(): iterable
    {
        yield 'modern delete' => [['DEL', 'Target'], IrcopAction::Delete, 'Target', ''];
        yield 'modern list' => [['LIST'], IrcopAction::List, '', ''];
        yield 'legacy add' => [['Target', 'ADD', 'admin'], IrcopAction::Add, 'Target', 'ADMIN'];
        yield 'unknown with target' => [['BOGUS', 'Target'], IrcopAction::Unknown, 'Target', ''];
    }

    #[Test]
    public function incompleteUnknownSyntaxDoesNotReachTheUseCase(): void
    {
        $handler = $this->createMock(ManageIrcopHandlerInterface::class);
        $handler->expects(self::never())->method('handle');
        $translation = new IrcopCommandTranslation();

        new IrcopCommand($handler)->execute($this->context(['BOGUS'], $translation));

        self::assertSame('error.syntax', $translation->lastKey);
        self::assertSame('ircop.syntax', $translation->lastParameters['%syntax%']);
    }

    /** @param array<string, string> $expectedParameters */
    #[Test]
    #[DataProvider('outcomePresentations')]
    public function presentsEverySimpleOutcome(ManageIrcopResult $result, string $expectedKey, array $expectedParameters): void
    {
        $handler = $this->createStub(ManageIrcopHandlerInterface::class);
        $handler->method('handle')->willReturn($result);
        $translation = new IrcopCommandTranslation();

        new IrcopCommand($handler)->execute($this->context(['ADD', 'Target', 'ADMIN'], $translation));

        self::assertSame($expectedKey, $translation->lastKey);
        foreach ($expectedParameters as $key => $value) {
            self::assertSame($value, $translation->lastParameters['%' . $key . '%'] ?? null);
        }
    }

    /** @return iterable<string, array{ManageIrcopResult, string, array<string, string>}> */
    public static function outcomePresentations(): iterable
    {
        yield 'added' => [new ManageIrcopResult(IrcopOutcome::Added, 'Target', 'ADMIN'), 'ircop.add.done', ['nickname' => 'Target', 'role' => 'ADMIN']];
        yield 'deleted' => [new ManageIrcopResult(IrcopOutcome::Deleted, 'Target'), 'ircop.del.done', ['nickname' => 'Target']];
        yield 'nick not registered' => [new ManageIrcopResult(IrcopOutcome::NickNotRegistered, 'Target'), 'error.nick_not_registered', ['nickname' => 'Target']];
        yield 'nick not active' => [new ManageIrcopResult(IrcopOutcome::NickNotActive, 'Target'), 'ircop.nick_not_active', ['nickname' => 'Target']];
        yield 'role not found' => [new ManageIrcopResult(IrcopOutcome::RoleNotFound, role: 'MISSING'), 'ircop.unknown_role', ['role' => 'MISSING', 'bot' => 'OperServ']];
        yield 'already assigned' => [new ManageIrcopResult(IrcopOutcome::AlreadyAssigned, 'Target', 'ADMIN'), 'ircop.already_admin', ['nickname' => 'Target', 'role' => 'ADMIN']];
        yield 'not assigned' => [new ManageIrcopResult(IrcopOutcome::NotAssigned, 'Target'), 'ircop.not_admin', ['nickname' => 'Target']];
        yield 'invalid request' => [new ManageIrcopResult(IrcopOutcome::InvalidRequest), 'error.syntax', ['syntax' => 'ircop.syntax']];
        yield 'unknown action' => [new ManageIrcopResult(IrcopOutcome::UnknownAction), 'ircop.unknown_sub', ['sub' => 'ADD']];
    }

    #[Test]
    public function presentsEmptyAndPopulatedLists(): void
    {
        $emptyHandler = $this->createStub(ManageIrcopHandlerInterface::class);
        $emptyHandler->method('handle')->willReturn(new ManageIrcopResult(IrcopOutcome::Listed));
        $emptyTranslation = new IrcopCommandTranslation();
        new IrcopCommand($emptyHandler)->execute($this->context(['LIST'], $emptyTranslation));
        self::assertSame('ircop.list.empty', $emptyTranslation->lastKey);

        $handler = $this->createStub(ManageIrcopHandlerInterface::class);
        $handler->method('handle')->willReturn(new ManageIrcopResult(
            IrcopOutcome::Listed,
            entries: [new IrcopListEntry('Target', 'ADMIN', new DateTimeImmutable('2026-01-01 12:00:00'))],
        ));
        $translation = new IrcopCommandTranslation();
        new IrcopCommand($handler)->execute($this->context(['LIST'], $translation));
        self::assertSame('ircop.list.header', $translation->lastKey);
    }

    /** @param list<string> $arguments */
    private function context(array $arguments, TranslatorInterface $translator, bool $withoutSender = false): OperServContext
    {
        return new OperServContext(
            $withoutSender ? null : new SenderView('001AAA', 'RootOper', 'ident', 'host', 'cloak', 'ip', true, true),
            $withoutSender ? null : new NickAccountData(42, 'RootOper', 'en'),
            'IRCOP',
            $arguments,
            new IrcopCommandNotifier(),
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
        );
    }
}

final class IrcopCommandTranslation implements TranslatorInterface
{
    public string $lastKey = '';

    /** @var array<string, mixed> */
    public array $lastParameters = [];

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->lastKey = $id;
        $this->lastParameters = $parameters;

        return $id;
    }

    public function getLocale(): string
    {
        return 'en';
    }
}

final class IrcopCommandNotifier implements OperServNotifierInterface
{
    public function sendNotice(string $targetUidOrNick, string $message): void {}

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void {}

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
