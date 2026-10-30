<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\OperServ\Adapter\In\Irc\Command\RoleCommand;
use App\OperServ\Adapter\In\Irc\OperServCommandRegistry;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\OperServ\Application\Port\Out\OperatorRoleRecord;
use App\OperServ\Application\UseCase\ManageRole\ManageRole;
use App\OperServ\Application\UseCase\ManageRole\ManageRoleHandlerInterface;
use App\OperServ\Application\UseCase\ManageRole\ManageRoleResult;
use App\OperServ\Application\UseCase\ManageRole\RoleAction;
use App\OperServ\Application\UseCase\ManageRole\RoleOutcome;
use App\Shared\Adapter\In\Irc\Presentation\Appearance;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function str_ends_with;

#[CoversClass(RoleCommand::class)]
#[CoversClass(ManageRole::class)]
#[CoversClass(ManageRoleResult::class)]
#[CoversClass(OperatorRoleRecord::class)]
final class RoleCommandTest extends TestCase
{
    #[Test]
    public function distinguishesSuccessfulAndRejectedRoleChanges(): void
    {
        $notifier = new RecordingRoleNotifier();
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: ['success' => '+', 'error' => '!'])));
        foreach ([RoleOutcome::Added, RoleOutcome::AlreadyExists] as $outcome) {
            $context = new OperServContext(
                new SenderView('001AAA', 'RootOper', 'ident', 'host', 'cloak', 'ip', true, true),
                null,
                'ROLE',
                ['ADD', 'ADMIN'],
                $notifier,
                new RecordingRoleTranslation(),
                'en',
                'UTC',
                'NOTICE',
                new OperServCommandRegistry([]),
                new ServiceNicknameRegistry([]),
                $this->createStub(OperatorAuthorizationQuery::class),
                $renderer,
            );
            new RoleCommand(new RecordingManageRoleHandler(true, new ManageRoleResult($outcome, self::role())))->execute($context);
        }
        self::assertSame(['+ role.add.done', '! role.already_exists'], $notifier->messages);
    }

    #[Test]
    public function permissionStatusesUseTheSelectedStyleWithoutChangingThePermissionRows(): void
    {
        $notifier = new RecordingRoleNotifier();
        $translation = $this->createStub(TranslatorInterface::class);
        $translation->method('trans')->willReturnCallback(static fn (string $id): string => 'role.perms.list.row' === $id
            ? '%assigned% | %permission% | %description%' : $id);
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(components: [
            'selected' => new Appearance(prefix: '[assigned]'),
            'unselected' => new Appearance(prefix: '[available]'),
        ])));
        $context = new OperServContext(
            new SenderView('001AAA', 'RootOper', 'ident', 'host', 'cloak', 'ip', true, true),
            null,
            'ROLE',
            ['PERMS', 'LIST', 'ADMIN'],
            $notifier,
            $translation,
            'en',
            'UTC',
            'NOTICE',
            new OperServCommandRegistry([]),
            new ServiceNicknameRegistry([]),
            $this->createStub(OperatorAuthorizationQuery::class),
            $renderer,
        );
        $result = new ManageRoleResult(RoleOutcome::PermissionsListed, self::role(), values: ['operserv.kill'], availableValues: ['chanserv.drop']);
        new RoleCommand(new RecordingManageRoleHandler(true, $result))->execute($context);
        self::assertSame('[available] | chanserv.drop | drop.short', $notifier->messages[2]);
        self::assertSame('[assigned] | operserv.kill | kill.short', $notifier->messages[3]);
    }

    /** @param list<string> $expectedSubcommands */
    #[Test]
    #[DataProvider('operclassCapabilities')]
    public function exposesOperclassSyntaxHelpAndSubcommandOnlyWhenSupported(
        bool $supported,
        string $expectedSyntax,
        string $expectedHelp,
        array $expectedSubcommands,
    ): void {
        $command = new RoleCommand(new RecordingManageRoleHandler($supported));

        self::assertSame('ROLE', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame(1, $command->getMinArgs());
        self::assertSame(2, $command->getOrder());
        self::assertSame('role.short', $command->getShortDescKey());
        self::assertTrue($command->isOperOnly());
        self::assertSame(OperatorAuthorizationAttribute::ROOT, $command->getRequiredPermission());
        self::assertSame($expectedSyntax, $command->getSyntaxKey());
        self::assertSame($expectedHelp, $command->getHelpKey());
        self::assertSame('role.example', $command->getExampleKey());
        self::assertSame($expectedSubcommands, array_column($command->getSubCommandHelp(), 'name'));
    }

    /** @return iterable<string, array{bool, string, string, list<string>}> */
    public static function operclassCapabilities(): iterable
    {
        yield 'unsupported protocol' => [
            false,
            'role.syntax',
            'role.help',
            ['LIST', 'ADD', 'DEL', 'PERMS', 'MODES', 'VHOST'],
        ];
        yield 'supported protocol' => [
            true,
            'role.syntax_operclass',
            'role.help_operclass',
            ['LIST', 'ADD', 'DEL', 'PERMS', 'MODES', 'VHOST', 'OPERCLASS'],
        ];
    }

    #[Test]
    public function rejectsOperclassAtTheIrcBoundaryWhenTheProtocolDoesNotSupportIt(): void
    {
        $handler = new RecordingManageRoleHandler(false);
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context(['OPERCLASS'], $notifier, $translator));

        self::assertSame([], $handler->commands);
        self::assertSame(['role.unknown_sub'], $notifier->messages);
        self::assertSame('OPERCLASS', $translator->parameters['role.unknown_sub']['%sub%']);
    }

    #[Test]
    public function supportedOperclassIsMappedToTheUseCaseEvenWhenItsCatalogMayNotBeEnumerable(): void
    {
        $handler = new RecordingManageRoleHandler(true, new ManageRoleResult(RoleOutcome::OperclassesListed));
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();
        $before = new DateTimeImmutable();

        new RoleCommand($handler)->execute($this->context(['OPERCLASS', 'LIST'], $notifier, $translator));

        $after = new DateTimeImmutable();
        self::assertCount(1, $handler->commands);
        self::assertSame(RoleAction::OperclassList, $handler->commands[0]->action);
        self::assertSame('RootOper', $handler->commands[0]->actorNickname);
        self::assertGreaterThanOrEqual($before, $handler->commands[0]->occurredAt);
        self::assertLessThanOrEqual($after, $handler->commands[0]->occurredAt);
        self::assertSame(['role.operclass.list.empty'], $notifier->messages);
    }

    /** @param list<string> $expectedTranslationCalls */
    #[Test]
    #[DataProvider('syntaxCapabilities')]
    public function syntaxErrorsUseTheCapabilitySpecificSyntax(
        bool $supported,
        string $expectedSyntax,
        array $expectedTranslationCalls,
    ): void {
        $handler = new RecordingManageRoleHandler($supported, new ManageRoleResult(RoleOutcome::InvalidRequest));
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context(['PERMS', 'ADD', 'ADMIN', 'invalid'], $notifier, $translator));

        self::assertSame($expectedTranslationCalls, $translator->calls);
        self::assertSame($expectedSyntax, $translator->parameters['error.syntax']['%syntax%']);
        self::assertSame(['error.syntax'], $notifier->messages);
    }

    #[Test]
    public function deletingRoleWithoutNameReturnsSyntaxErrorInsteadOfLookingUpEmptyRole(): void
    {
        $handler = new RecordingManageRoleHandler(true, new ManageRoleResult(RoleOutcome::NotFound));
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context(['DEL'], $notifier, $translator));

        self::assertSame([], $handler->commands);
        self::assertSame(['error.syntax'], $notifier->messages);
        self::assertSame(['role.del.syntax', 'error.syntax'], $translator->calls);
        self::assertSame('role.del.syntax', $translator->parameters['error.syntax']['%syntax%']);
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('missingRequiredArguments')]
    public function missingRequiredArgumentsReturnTheSpecificSyntax(
        array $arguments,
        string $expectedSyntax,
    ): void {
        $handler = new RecordingManageRoleHandler(true);
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context($arguments, $notifier, $translator));

        self::assertSame([], $handler->commands);
        self::assertSame(['error.syntax'], $notifier->messages);
        self::assertSame([$expectedSyntax, 'error.syntax'], $translator->calls);
        self::assertSame($expectedSyntax, $translator->parameters['error.syntax']['%syntax%']);
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('extraNestedOperands')]
    public function rejectsExtraNestedOperandsUsingTheSubcommandSyntax(array $arguments, string $syntaxKey): void
    {
        $handler = new RecordingManageRoleHandler(true);
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context($arguments, $notifier, $translator));

        self::assertSame([], $handler->commands);
        self::assertSame(['error.syntax'], $notifier->messages);
        self::assertSame($syntaxKey, $translator->parameters['error.syntax']['%syntax%']);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function extraNestedOperands(): iterable
    {
        yield 'extra permission operand' => [['PERMS', 'ADD', 'ADMIN', 'operserv.kill', 'ignored'], 'role.perms.syntax'];
        yield 'extra modes operand' => [['MODES', 'VIEW', 'ADMIN', 'extra'], 'role.modes.syntax'];
        yield 'extra vhost operand' => [['VHOST', 'VIEW', 'ADMIN', 'extra'], 'role.vhost.syntax'];
    }

    #[Test]
    public function roleDeleteContinuesToIgnoreTrailingArguments(): void
    {
        $handler = new RecordingManageRoleHandler(true);
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context(['DEL', 'ADMIN', 'ignored'], $notifier, $translator));

        self::assertCount(1, $handler->commands);
        self::assertSame(RoleAction::Delete, $handler->commands[0]->action);
        self::assertSame('ADMIN', $handler->commands[0]->roleName);
        self::assertSame(['role.unknown_sub_operclass'], $notifier->messages);
    }

    /** @return iterable<string, array{list<string>, string}> */
    public static function missingRequiredArguments(): iterable
    {
        yield 'add role name' => [['ADD'], 'role.add.syntax'];
        yield 'permissions role name' => [['PERMS'], 'role.perms.syntax'];
        yield 'permission add role' => [['PERMS', 'ADD'], 'role.perms.syntax'];
        yield 'permission to add' => [['PERMS', 'ADD', 'ADMIN'], 'role.perms.syntax'];
        yield 'permission delete role' => [['PERMS', 'DEL'], 'role.perms.syntax'];
        yield 'permission to delete' => [['PERMS', 'DEL', 'ADMIN'], 'role.perms.syntax'];
        yield 'permission list role' => [['PERMS', 'LIST'], 'role.perms.syntax'];
        yield 'permission clear role' => [['PERMS', 'CLEAR'], 'role.perms.syntax'];
        yield 'permissions clear action' => [['PERMS', 'ADMIN'], 'role.perms.syntax'];
        yield 'old permissions add order' => [['PERMS', 'ADMIN', 'ADD', 'operserv.kill'], 'role.perms.syntax'];
        yield 'old permissions list order' => [['PERMS', 'ADMIN', 'LIST'], 'role.perms.syntax'];
        yield 'old permissions clear order' => [['PERMS', 'ADMIN', 'CLEAR'], 'role.perms.syntax'];
        yield 'modes role name' => [['MODES'], 'role.modes.syntax'];
        yield 'modes view role' => [['MODES', 'VIEW'], 'role.modes.syntax'];
        yield 'modes set role' => [['MODES', 'SET'], 'role.modes.syntax'];
        yield 'old modes order' => [['MODES', 'ADMIN', 'VIEW'], 'role.modes.syntax'];
        yield 'vhost role name' => [['VHOST'], 'role.vhost.syntax'];
        yield 'vhost view role' => [['VHOST', 'VIEW'], 'role.vhost.syntax'];
        yield 'vhost set role' => [['VHOST', 'SET'], 'role.vhost.syntax'];
        yield 'old vhost order' => [['VHOST', 'ADMIN', 'VIEW'], 'role.vhost.syntax'];
        yield 'operclass role name' => [['OPERCLASS'], 'role.operclass.syntax'];
        yield 'operclass view role' => [['OPERCLASS', 'VIEW'], 'role.operclass.syntax'];
        yield 'operclass set role' => [['OPERCLASS', 'SET'], 'role.operclass.syntax'];
        yield 'old operclass order' => [['OPERCLASS', 'ADMIN', 'VIEW'], 'role.operclass.syntax'];
        yield 'operclass list has no role parameter' => [['OPERCLASS', 'LIST', 'ADMIN'], 'role.operclass.syntax'];
    }

    /** @return iterable<string, array{bool, string, list<string>}> */
    public static function syntaxCapabilities(): iterable
    {
        yield 'unsupported protocol' => [false, 'role.syntax', ['role.syntax', 'error.syntax']];
        yield 'supported protocol' => [true, 'role.syntax_operclass', ['role.syntax_operclass', 'error.syntax']];
    }

    #[Test]
    #[DataProvider('unknownSubcommandCapabilities')]
    public function unknownSubcommandPresentationMentionsOperclassOnlyWhenSupported(bool $supported, string $expectedKey): void
    {
        $handler = new RecordingManageRoleHandler($supported, new ManageRoleResult(RoleOutcome::UnknownAction));
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context(['UNKNOWN'], $notifier, $translator));

        self::assertSame([$expectedKey], $notifier->messages);
        self::assertSame('UNKNOWN', $translator->parameters[$expectedKey]['%sub%']);
    }

    /** @return iterable<string, array{bool, string}> */
    public static function unknownSubcommandCapabilities(): iterable
    {
        yield 'unsupported protocol' => [false, 'role.unknown_sub'];
        yield 'supported protocol' => [true, 'role.unknown_sub_operclass'];
    }

    #[Test]
    public function missingSenderDoesNotReachTheUseCaseOrProduceOutput(): void
    {
        $handler = new RecordingManageRoleHandler(true);
        $notifier = new RecordingRoleNotifier();

        new RoleCommand($handler)->execute($this->context(['LIST'], $notifier, new RecordingRoleTranslation(), withoutSender: true));

        self::assertSame([], $handler->commands);
        self::assertSame([], $notifier->messages);
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataProvider('parsedActions')]
    public function mapsAllSupportedCommandShapes(
        array $arguments,
        RoleAction $expectedAction,
        string $expectedRole,
        string $expectedValue,
        string $expectedDescription,
    ): void {
        $handler = new RecordingManageRoleHandler(true);

        new RoleCommand($handler)->execute($this->context(
            $arguments,
            new RecordingRoleNotifier(),
            new RecordingRoleTranslation(),
        ));

        self::assertCount(1, $handler->commands);
        self::assertSame($expectedAction, $handler->commands[0]->action);
        self::assertSame($expectedRole, $handler->commands[0]->roleName);
        self::assertSame($expectedValue, $handler->commands[0]->value);
        self::assertSame($expectedDescription, $handler->commands[0]->description);
    }

    /** @return iterable<string, array{list<string>, RoleAction, string, string, string}> */
    public static function parsedActions(): iterable
    {
        yield 'add' => [['ADD', 'admin', 'description', 'with spaces'], RoleAction::Add, 'ADMIN', '', 'description with spaces'];
        yield 'delete' => [['DEL', 'admin'], RoleAction::Delete, 'ADMIN', '', ''];
        yield 'list roles' => [['LIST'], RoleAction::List, '', '', ''];
        yield 'list permissions' => [['PERMS', 'LIST', 'admin'], RoleAction::PermissionList, 'ADMIN', '', ''];
        yield 'add permission' => [['PERMS', 'ADD', 'admin', 'operserv.kill'], RoleAction::PermissionAdd, 'ADMIN', 'operserv.kill', ''];
        yield 'add all permissions' => [['PERMS', 'ADD', 'admin', 'all'], RoleAction::PermissionAddAll, 'ADMIN', 'all', ''];
        yield 'delete permission' => [['PERMS', 'DEL', 'admin', 'operserv.kill'], RoleAction::PermissionDelete, 'ADMIN', 'operserv.kill', ''];
        yield 'clear permissions' => [['PERMS', 'CLEAR', 'admin'], RoleAction::PermissionClear, 'ADMIN', '', ''];
        yield 'unknown permissions verb' => [['PERMS', 'admin', 'BOGUS'], RoleAction::Unknown, 'ADMIN', '', ''];
        yield 'view modes' => [['MODES', 'VIEW', 'admin'], RoleAction::ModesView, 'ADMIN', '', ''];
        yield 'set modes' => [['MODES', 'SET', 'admin', 'oO'], RoleAction::ModesSet, 'ADMIN', 'oO', ''];
        yield 'clear modes with empty value' => [['MODES', 'SET', 'admin'], RoleAction::ModesSet, 'ADMIN', '', ''];
        yield 'unknown modes verb' => [['MODES', 'BOGUS', 'admin'], RoleAction::Unknown, 'ADMIN', '', ''];
        yield 'view vhost' => [['VHOST', 'VIEW', 'admin'], RoleAction::VhostView, 'ADMIN', '', ''];
        yield 'set vhost' => [['VHOST', 'SET', 'admin', 'staff.example'], RoleAction::VhostSet, 'ADMIN', 'staff.example', ''];
        yield 'clear vhost with empty value' => [['VHOST', 'SET', 'admin'], RoleAction::VhostSet, 'ADMIN', '', ''];
        yield 'unknown vhost verb' => [['VHOST', 'BOGUS', 'admin'], RoleAction::Unknown, 'ADMIN', '', ''];
        yield 'list operclasses' => [['OPERCLASS', 'LIST'], RoleAction::OperclassList, '', '', ''];
        yield 'view operclass' => [['OPERCLASS', 'VIEW', 'admin'], RoleAction::OperclassView, 'ADMIN', '', ''];
        yield 'set operclass' => [['OPERCLASS', 'SET', 'admin', 'netadmin'], RoleAction::OperclassSet, 'ADMIN', 'netadmin', ''];
        yield 'clear operclass with empty value' => [['OPERCLASS', 'SET', 'admin'], RoleAction::OperclassSet, 'ADMIN', '', ''];
        yield 'unknown operclass verb' => [['OPERCLASS', 'BOGUS', 'admin'], RoleAction::Unknown, 'BOGUS', '', ''];
        yield 'unknown subcommand' => [['BOGUS', 'admin', 'value'], RoleAction::Unknown, 'ADMIN', '', ''];
    }

    /** @param array<string, string> $expectedParameters */
    #[Test]
    #[DataProvider('simplePresentations')]
    public function presentsEverySimpleOutcome(ManageRoleResult $result, string $expectedKey, array $expectedParameters): void
    {
        $handler = new RecordingManageRoleHandler(true, $result);
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context(
            ['PERMS', 'ADD', 'admin', 'missing'],
            $notifier,
            $translator,
        ));

        self::assertSame([$expectedKey], $notifier->messages);
        foreach ($expectedParameters as $key => $value) {
            self::assertSame($value, $translator->parameters[$expectedKey]['%' . $key . '%'] ?? null);
        }
    }

    /** @return iterable<string, array{ManageRoleResult, string, array<string, string>}> */
    public static function simplePresentations(): iterable
    {
        $role = self::role();

        yield 'added' => [new ManageRoleResult(RoleOutcome::Added, $role), 'role.add.done', ['role' => 'ADMIN']];
        yield 'deleted' => [new ManageRoleResult(RoleOutcome::Deleted, $role), 'role.del.done', ['role' => 'ADMIN']];
        yield 'already exists' => [new ManageRoleResult(RoleOutcome::AlreadyExists, $role), 'role.already_exists', ['role' => 'ADMIN']];
        yield 'not found' => [new ManageRoleResult(RoleOutcome::NotFound), 'role.not_found', ['role' => 'ADMIN']];
        yield 'protected' => [new ManageRoleResult(RoleOutcome::Protected, $role), 'role.protected', ['role' => 'ADMIN']];
        yield 'permission added' => [new ManageRoleResult(RoleOutcome::PermissionAdded, $role, values: ['operserv.kill']), 'role.perms.add.done', ['role' => 'ADMIN', 'perm' => 'operserv.kill']];
        yield 'all permissions added' => [new ManageRoleResult(RoleOutcome::PermissionAddedAll, $role, count: 4), 'role.perms.add.all_done', ['role' => 'ADMIN', 'count' => '4']];
        yield 'permission already assigned' => [new ManageRoleResult(RoleOutcome::PermissionAlreadyAssigned, $role), 'role.perms.already_has', ['role' => 'ADMIN', 'perm' => 'missing']];
        yield 'permission not found' => [new ManageRoleResult(RoleOutcome::PermissionNotFound), 'role.perms.not_found', ['perm' => 'missing']];
        yield 'permission removed' => [new ManageRoleResult(RoleOutcome::PermissionRemoved, $role, values: ['operserv.kill']), 'role.perms.del.done', ['role' => 'ADMIN', 'perm' => 'operserv.kill']];
        yield 'permission missing' => [new ManageRoleResult(RoleOutcome::PermissionMissing, $role), 'role.perms.does_not_have', ['role' => 'ADMIN', 'perm' => 'missing']];
        yield 'permissions cleared' => [new ManageRoleResult(RoleOutcome::PermissionsCleared, $role, count: 3), 'role.perms.clear.done', ['role' => 'ADMIN', 'count' => '3']];
        yield 'permissions empty' => [new ManageRoleResult(RoleOutcome::PermissionsEmpty, $role), 'role.perms.clear.empty', ['role' => 'ADMIN']];
        yield 'modes set' => [new ManageRoleResult(RoleOutcome::ModesSet, $role, values: ['o', 'O']), 'role.modes.set.done', ['role' => 'ADMIN', 'modes' => '+oO']];
        yield 'modes cleared' => [new ManageRoleResult(RoleOutcome::ModesCleared, $role), 'role.modes.set.cleared', ['role' => 'ADMIN']];
        yield 'invalid modes' => [new ManageRoleResult(RoleOutcome::InvalidModes, availableValues: ['o', 'O'], values: ['x']), 'role.modes.set.invalid_modes', ['invalid' => '+x', 'valid' => '+oO']];
        yield 'modes not supported' => [new ManageRoleResult(RoleOutcome::ModesNotSupported), 'role.modes.set.no_irc_user_modes', []];
        yield 'vhost set' => [new ManageRoleResult(RoleOutcome::VhostSet, $role), 'role.vhost.set.done', ['role' => 'ADMIN']];
        yield 'vhost cleared' => [new ManageRoleResult(RoleOutcome::VhostCleared, $role), 'role.vhost.set.cleared', ['role' => 'ADMIN']];
        yield 'invalid vhost' => [new ManageRoleResult(RoleOutcome::InvalidVhost), 'role.vhost.set.invalid', []];
        yield 'operclass set' => [new ManageRoleResult(RoleOutcome::OperclassSet, $role), 'role.operclass.set.done', ['role' => 'ADMIN']];
        yield 'operclass cleared' => [new ManageRoleResult(RoleOutcome::OperclassCleared, $role), 'role.operclass.set.cleared', ['role' => 'ADMIN']];
        yield 'operclass unavailable' => [new ManageRoleResult(RoleOutcome::OperclassNotAvailable, availableValues: ['netadmin', 'oper']), 'role.operclass.set.not_available', ['operclass' => 'missing', 'available' => 'netadmin, oper']];
        yield 'operclass unsupported' => [new ManageRoleResult(RoleOutcome::OperclassNotSupported), 'role.operclass.list.not_supported', []];
    }

    #[Test]
    public function notFoundMentionsTheRequestedRoleInsteadOfTheProvidedValue(): void
    {
        $handler = new RecordingManageRoleHandler(true, new ManageRoleResult(RoleOutcome::NotFound));
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context(
            ['VHOST', 'SET', 'missing', 'staff.example'],
            $notifier,
            $translator,
        ));

        self::assertSame(['role.not_found'], $notifier->messages);
        self::assertSame('MISSING', $translator->parameters['role.not_found']['%role%']);
    }

    #[Test]
    public function presentsRolePermissionAndValueCollections(): void
    {
        $this->assertCollectionPresentation(
            new ManageRoleResult(RoleOutcome::Listed),
            ['LIST'],
            ['role.list.empty'],
        );
        $this->assertCollectionPresentation(
            new ManageRoleResult(RoleOutcome::Listed, roles: [self::role(), new OperatorRoleRecord(2, 'OPER', 'Operator', false)]),
            ['LIST'],
            ['role.list.header'],
        );
        $this->assertCollectionPresentation(
            new ManageRoleResult(RoleOutcome::PermissionsListed, self::role(), values: ['operserv.kill'], availableValues: ['chanserv.drop']),
            ['PERMS', 'LIST', 'ADMIN'],
            ['role.perms.list.header', 'role.perms.list.columns', 'role.perms.list.row'],
        );
        $this->assertCollectionPresentation(
            new ManageRoleResult(RoleOutcome::ModesViewed, self::role()),
            ['MODES', 'VIEW', 'ADMIN'],
            ['role.modes.view.empty'],
        );
        $this->assertCollectionPresentation(
            new ManageRoleResult(RoleOutcome::ModesViewed, self::role(), values: ['o', 'O']),
            ['MODES', 'VIEW', 'ADMIN'],
            ['role.modes.view.header', 'role.modes.view.line'],
        );
        $this->assertCollectionPresentation(
            new ManageRoleResult(RoleOutcome::VhostViewed, self::role()),
            ['VHOST', 'VIEW', 'ADMIN'],
            ['role.vhost.view.empty'],
        );
        $this->assertCollectionPresentation(
            new ManageRoleResult(RoleOutcome::VhostViewed, self::role(), values: ['staff.example']),
            ['VHOST', 'VIEW', 'ADMIN'],
            ['role.vhost.view.header', 'role.vhost.view.line', 'role.vhost.view.example'],
        );
        $this->assertCollectionPresentation(
            new ManageRoleResult(RoleOutcome::OperclassesListed, values: ['netadmin', 'oper']),
            ['OPERCLASS', 'LIST'],
            ['role.operclass.list.header'],
        );
        $this->assertCollectionPresentation(
            new ManageRoleResult(RoleOutcome::OperclassViewed, self::role()),
            ['OPERCLASS', 'VIEW', 'ADMIN'],
            ['role.operclass.view.empty'],
        );
        $this->assertCollectionPresentation(
            new ManageRoleResult(RoleOutcome::OperclassViewed, self::role(), values: ['netadmin'], availableValues: ['netadmin', 'oper']),
            ['OPERCLASS', 'VIEW', 'ADMIN'],
            ['role.operclass.view.line', 'role.operclass.view.available'],
        );
    }

    #[Test]
    public function permissionListShowsEveryPermissionWithAssignedStatusAndDescription(): void
    {
        $handler = new RecordingManageRoleHandler(true, new ManageRoleResult(
            RoleOutcome::PermissionsListed,
            self::role(),
            values: ['operserv.kill'],
            availableValues: ['nickserv.list', 'chanserv.drop', 'chanserv.drop.force', 'chanserv.level_founder'],
        ));
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context(['PERMS', 'LIST', 'ADMIN'], $notifier, $translator));

        self::assertSame([
            'role.perms.list.header',
            'role.perms.list.columns',
            'role.perms.list.row',
            'role.perms.list.row',
            'role.perms.list.row',
            'role.perms.list.row',
            'role.perms.list.row',
        ], $notifier->messages);

        $rows = [];
        foreach ($translator->translations as $translation) {
            if ('role.perms.list.row' === $translation['key']) {
                $rows[] = $translation['parameters'];
            }
        }

        self::assertSame([
            [
                '%bot%' => 'OperServ',
                '%assigned%' => 'role.perms.list.unassigned',
                '%permission%' => 'chanserv.drop',
                '%description%' => 'drop.short',
            ],
            [
                '%bot%' => 'OperServ',
                '%assigned%' => 'role.perms.list.unassigned',
                '%permission%' => 'chanserv.drop.force',
                '%description%' => 'drop.force.short',
            ],
            [
                '%bot%' => 'OperServ',
                '%assigned%' => 'role.perms.list.unassigned',
                '%permission%' => 'chanserv.level_founder',
                '%description%' => 'level_founder.short',
            ],
            [
                '%bot%' => 'OperServ',
                '%assigned%' => 'role.perms.list.unassigned',
                '%permission%' => 'nickserv.list',
                '%description%' => 'list.short',
            ],
            [
                '%bot%' => 'OperServ',
                '%assigned%' => 'role.perms.list.assigned',
                '%permission%' => 'operserv.kill',
                '%description%' => 'kill.short',
            ],
        ], $rows);

        $descriptionLookups = [];
        foreach ($translator->translations as $translation) {
            if (str_ends_with($translation['key'], '.short')) {
                $descriptionLookups[] = [$translation['key'], $translation['domain']];
            }
        }

        self::assertSame([
            ['drop.short', 'chanserv'],
            ['drop.force.short', 'chanserv'],
            ['level_founder.short', 'chanserv'],
            ['list.short', 'nickserv'],
            ['kill.short', 'operserv'],
        ], $descriptionLookups);
    }

    #[Test]
    public function permissionListPreservesMalformedPermissionDescriptions(): void
    {
        $handler = new RecordingManageRoleHandler(true, new ManageRoleResult(
            RoleOutcome::PermissionsListed,
            self::role(),
            availableValues: ['invalid', '.missing-domain', 'missing-operation.'],
        ));
        $notifier = new RecordingRoleNotifier();
        $translator = new RecordingRoleTranslation();

        new RoleCommand($handler)->execute($this->context(['PERMS', 'LIST', 'ADMIN'], $notifier, $translator));

        $descriptions = [];
        foreach ($translator->translations as $translation) {
            if ('role.perms.list.row' === $translation['key']) {
                $descriptions[] = $translation['parameters']['%description%'];
            }
        }
        self::assertSame(['.missing-domain', 'invalid', 'missing-operation.'], $descriptions);
        self::assertSame([
            'role.perms.list.header',
            'role.perms.list.columns',
            'role.perms.list.row',
            'role.perms.list.row',
            'role.perms.list.row',
        ], $notifier->messages);
    }

    /** @param list<string> $arguments
     * @param list<string> $expectedMessages
     */
    private function assertCollectionPresentation(ManageRoleResult $result, array $arguments, array $expectedMessages): void
    {
        $notifier = new RecordingRoleNotifier();
        new RoleCommand(new RecordingManageRoleHandler(true, $result))->execute($this->context(
            $arguments,
            $notifier,
            new RecordingRoleTranslation(),
        ));

        foreach ($expectedMessages as $message) {
            self::assertContains($message, $notifier->messages);
        }
    }

    private static function role(): OperatorRoleRecord
    {
        return new OperatorRoleRecord(1, 'ADMIN', 'Administrator', true);
    }

    /** @param list<string> $arguments */
    private function context(
        array $arguments,
        OperServNotifierInterface $notifier,
        TranslatorInterface $translator,
        bool $withoutSender = false,
    ): OperServContext {
        return new OperServContext(
            $withoutSender ? null : new SenderView('001AAA', 'RootOper', 'ident', 'host', 'cloak', 'ip', true, true),
            null,
            'ROLE',
            $arguments,
            $notifier,
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

final class RecordingManageRoleHandler implements ManageRoleHandlerInterface
{
    /** @var list<ManageRole> */
    public array $commands = [];

    public function __construct(
        private readonly bool $supportsOperclass,
        private readonly ManageRoleResult $result = new ManageRoleResult(RoleOutcome::UnknownAction),
    ) {}

    public function supportsOperclass(): bool
    {
        return $this->supportsOperclass;
    }

    public function handle(ManageRole $command): ManageRoleResult
    {
        $this->commands[] = $command;

        return $this->result;
    }
}

final class RecordingRoleTranslation implements TranslatorInterface
{
    /** @var list<string> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public array $parameters = [];

    /** @var list<array{key: string, parameters: array<string, mixed>, domain: ?string}> */
    public array $translations = [];

    /** @param array<string, mixed> $parameters */
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $this->calls[] = $id;
        $this->parameters[$id] = $parameters;
        $this->translations[] = ['key' => $id, 'parameters' => $parameters, 'domain' => $domain];

        return $id;
    }

    public function getLocale(): string
    {
        return 'en';
    }
}

final class RecordingRoleNotifier implements OperServNotifierInterface
{
    /** @var list<string> */
    public array $messages = [];

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
