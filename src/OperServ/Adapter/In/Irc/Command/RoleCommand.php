<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\UseCase\ManageRole\ManageRole;
use App\OperServ\Application\UseCase\ManageRole\ManageRoleHandlerInterface;
use App\OperServ\Application\UseCase\ManageRole\ManageRoleResult;
use App\OperServ\Application\UseCase\ManageRole\RoleAction;
use App\OperServ\Application\UseCase\ManageRole\RoleOutcome;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use DateTimeImmutable;

use function array_merge;
use function array_slice;
use function count;
use function explode;
use function implode;
use function in_array;
use function sort;
use function sprintf;
use function strtoupper;
use function trim;

final readonly class RoleCommand implements OperServCommandInterface
{
    public function __construct(private ManageRoleHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'ROLE';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        $operclassSupported = $this->handler->supportsOperclass();
        $variants = [
            'LIST' => new CommandArgumentDefinition('role.list.syntax'),
            'ADD' => new CommandArgumentDefinition('role.add.syntax', [
                new Argument('role'),
                new Argument('description', optional: true, rest: true),
            ]),
            'DEL' => new CommandArgumentDefinition('role.del.syntax', [new Argument('role')]),
            'PERMS' => new CommandArgumentDefinition('role.perms.syntax', [new Argument('action')], [
                'ADD' => new CommandArgumentDefinition('role.perms.syntax', [new Argument('role'), new Argument('permission')], allowExtra: false),
                'DEL' => new CommandArgumentDefinition('role.perms.syntax', [new Argument('role'), new Argument('permission')], allowExtra: false),
                'LIST' => new CommandArgumentDefinition('role.perms.syntax', [new Argument('role')], allowExtra: false),
                'CLEAR' => new CommandArgumentDefinition('role.perms.syntax', [new Argument('role')], allowExtra: false),
            ], unknownVariant: new CommandArgumentDefinition('role.perms.syntax', [new Argument('role')], allowExtra: false)),
            'MODES' => new CommandArgumentDefinition('role.modes.syntax', [new Argument('action')], [
                'VIEW' => new CommandArgumentDefinition('role.modes.syntax', [new Argument('role')], allowExtra: false),
                'SET' => new CommandArgumentDefinition('role.modes.syntax', [new Argument('role'), new Argument('modes', optional: true)], allowExtra: false),
            ], unknownVariant: new CommandArgumentDefinition('role.modes.syntax', [new Argument('role')])),
            'VHOST' => new CommandArgumentDefinition('role.vhost.syntax', [new Argument('action')], [
                'VIEW' => new CommandArgumentDefinition('role.vhost.syntax', [new Argument('role')], allowExtra: false),
                'SET' => new CommandArgumentDefinition('role.vhost.syntax', [new Argument('role'), new Argument('vhost', optional: true)], allowExtra: false),
            ], unknownVariant: new CommandArgumentDefinition('role.vhost.syntax', [new Argument('role')])),
        ];
        if ($operclassSupported) {
            $variants['OPERCLASS'] = new CommandArgumentDefinition(
                'role.operclass.syntax',
                [new Argument('action')],
                [
                    'LIST' => new CommandArgumentDefinition('role.operclass.syntax', [], allowExtra: false),
                    'VIEW' => new CommandArgumentDefinition('role.operclass.syntax', [new Argument('role')], allowExtra: false),
                    'SET' => new CommandArgumentDefinition('role.operclass.syntax', [new Argument('role'), new Argument('operclass', optional: true)], allowExtra: false),
                ],
                unknownVariant: new CommandArgumentDefinition('role.operclass.syntax', [new Argument('role')]),
            );
        }

        return new CommandArgumentDefinition(
            $operclassSupported ? 'role.syntax_operclass' : 'role.syntax',
            [new Argument('subcommand')],
            $variants,
        );
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return $this->handler->supportsOperclass() ? 'role.help_operclass' : 'role.help';
    }

    public function getExampleKey(): string
    {
        return 'role.example';
    }

    public function getOrder(): int
    {
        return 2;
    }

    public function getShortDescKey(): string
    {
        return 'role.short';
    }

    public function getSubCommandHelp(): array
    {
        $commands = [['name' => 'LIST', 'desc_key' => 'role.list.short', 'help_key' => 'role.list.help', 'syntax_key' => 'role.list.syntax', 'example_key' => 'role.list.example'], ['name' => 'ADD', 'desc_key' => 'role.add.short', 'help_key' => 'role.add.help', 'syntax_key' => 'role.add.syntax', 'example_key' => 'role.add.example'], ['name' => 'DEL', 'desc_key' => 'role.del.short', 'help_key' => 'role.del.help', 'syntax_key' => 'role.del.syntax', 'example_key' => 'role.del.example'], ['name' => 'PERMS', 'desc_key' => 'role.perms.short', 'help_key' => 'role.perms.help', 'syntax_key' => 'role.perms.syntax', 'example_key' => 'role.perms.example'], ['name' => 'MODES', 'desc_key' => 'role.modes.short', 'help_key' => 'role.modes.help', 'syntax_key' => 'role.modes.syntax', 'example_key' => 'role.modes.example'], ['name' => 'VHOST', 'desc_key' => 'role.vhost.short', 'help_key' => 'role.vhost.help', 'syntax_key' => 'role.vhost.syntax', 'example_key' => 'role.vhost.example']];
        if ($this->handler->supportsOperclass()) {
            $commands[] = ['name' => 'OPERCLASS', 'desc_key' => 'role.operclass.short', 'help_key' => 'role.operclass.help', 'syntax_key' => 'role.operclass.syntax', 'example_key' => 'role.operclass.example'];
        }

        return $commands;
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return OperatorAuthorizationAttribute::ROOT;
    }

    public function execute(OperServContext $c): void
    {
        if (null === $c->sender) {
            return;
        }
        $sub = strtoupper($c->args[0] ?? '');
        if ('OPERCLASS' === $sub && !$this->handler->supportsOperclass()) {
            $c->reply('role.unknown_sub', ['%sub%' => $sub]);

            return;
        }
        $validation = CommandArgumentValidator::validate($this->getArgumentDefinition(), $c->args);
        $syntaxKey = $validation->syntaxKey;
        if (!$validation->recognized && null === $syntaxKey) {
            $syntaxKey = $this->syntaxForUnrecognizedVariant($c, $sub);
        }
        if (null !== $syntaxKey) {
            $c->reply('error.syntax', ['%syntax%' => $c->trans($syntaxKey)]);

            return;
        }
        [$action,$role,$value,$description] = $this->parse($c, $sub);
        $r = $this->handler->handle(new ManageRole($action, $c->sender->nick, new DateTimeImmutable(), $role, $value, description: $description));
        $this->present($c, $sub, $r, $role, $value);
    }

    private function syntaxForUnrecognizedVariant(OperServContext $c, string $sub): ?string
    {
        $first = strtoupper(trim($c->args[1] ?? ''));
        $second = strtoupper(trim($c->args[2] ?? ''));
        $argumentCount = count($c->args);

        return match ($sub) {
            'PERMS' => '' === $first || '' === $second || in_array($second, ['ADD', 'DEL', 'LIST', 'CLEAR'], true) || 3 !== $argumentCount
                ? 'role.perms.syntax'
                : null,
            'MODES' => '' === $first || '' === $second || in_array($second, ['VIEW', 'SET'], true)
                ? 'role.modes.syntax'
                : null,
            'VHOST' => '' === $first || '' === $second || in_array($second, ['VIEW', 'SET'], true)
                ? 'role.vhost.syntax'
                : null,
            'OPERCLASS' => '' === $first || '' === $second || in_array($second, ['VIEW', 'SET', 'LIST'], true)
                ? 'role.operclass.syntax'
                : null,
            default => null,
        };
    }

    /** @return array{RoleAction, string, string, string} */
    private function parse(OperServContext $c, string $sub): array
    {
        $first = strtoupper(trim($c->args[1] ?? ''));
        $second = strtoupper(trim($c->args[2] ?? ''));
        $third = trim($c->args[3] ?? '');

        return match ($sub) {
            'ADD' => [RoleAction::Add, $first, '', trim(implode(' ', array_slice($c->args, 2)))],
            'DEL' => [RoleAction::Delete, $first, '', ''],
            'LIST' => [RoleAction::List, '', '', ''],
            'PERMS' => match ($first) {
                'LIST' => [RoleAction::PermissionList, strtoupper($c->args[2] ?? ''), '', ''],
                'ADD' => ['ALL' === strtoupper($third) ? RoleAction::PermissionAddAll : RoleAction::PermissionAdd, strtoupper($c->args[2] ?? ''), $third, ''],
                'DEL' => [RoleAction::PermissionDelete, strtoupper($c->args[2] ?? ''), $third, ''],
                'CLEAR' => [RoleAction::PermissionClear, strtoupper($c->args[2] ?? ''), '', ''],
                default => [RoleAction::Unknown, $first, $third, ''],
            },
            'MODES' => [match ($first) {
                'VIEW' => RoleAction::ModesView,
                'SET' => RoleAction::ModesSet,
                default => RoleAction::Unknown,
            }, strtoupper($c->args[2] ?? ''), $third, ''],
            'VHOST' => [match ($first) {
                'VIEW' => RoleAction::VhostView,
                'SET' => RoleAction::VhostSet,
                default => RoleAction::Unknown,
            }, strtoupper($c->args[2] ?? ''), $third, ''],
            'OPERCLASS' => match ($first) {
                'LIST' => [RoleAction::OperclassList, '', '', ''],
                'VIEW' => [RoleAction::OperclassView, strtoupper($c->args[2] ?? ''), '', ''],
                'SET' => [RoleAction::OperclassSet, strtoupper($c->args[2] ?? ''), $third, ''],
                default => [RoleAction::Unknown, $first, $third, ''],
            },
            default => [RoleAction::Unknown, $first, $third, ''],
        };
    }

    private function present(OperServContext $c, string $sub, ManageRoleResult $r, string $role, string $value): void
    {
        match ($r->outcome) {
            RoleOutcome::Added => $c->reply('role.add.done', ['%role%' => $r->role?->name]),RoleOutcome::Deleted => $c->reply('role.del.done', ['%role%' => $r->role?->name]),RoleOutcome::AlreadyExists => $c->reply('role.already_exists', ['%role%' => $r->role?->name]),RoleOutcome::NotFound => $c->reply('role.not_found', ['%role%' => strtoupper($role)]),RoleOutcome::Protected => $c->reply('role.protected', ['%role%' => $r->role?->name]),RoleOutcome::Listed => $this->roles($c, $r),RoleOutcome::PermissionsListed => $this->permissions($c, $r),RoleOutcome::PermissionAdded => $c->reply('role.perms.add.done', ['%role%' => $r->role?->name, '%perm%' => $r->values[0] ?? '']),RoleOutcome::PermissionAddedAll => $c->reply('role.perms.add.all_done', ['%role%' => $r->role?->name, '%count%' => (string) $r->count]),RoleOutcome::PermissionAlreadyAssigned => $c->reply('role.perms.already_has', ['%role%' => $r->role?->name, '%perm%' => $value]),RoleOutcome::PermissionNotFound => $c->reply('role.perms.not_found', ['%perm%' => $value]),RoleOutcome::PermissionRemoved => $c->reply('role.perms.del.done', ['%role%' => $r->role?->name, '%perm%' => $r->values[0] ?? '']),RoleOutcome::PermissionMissing => $c->reply('role.perms.does_not_have', ['%role%' => $r->role?->name, '%perm%' => $value]),RoleOutcome::PermissionsCleared => $c->reply('role.perms.clear.done', ['%role%' => $r->role?->name, '%count%' => (string) $r->count]),RoleOutcome::PermissionsEmpty => $c->reply('role.perms.clear.empty', ['%role%' => $r->role?->name]),RoleOutcome::ModesViewed => $this->modes($c, $r),RoleOutcome::ModesSet => $c->reply('role.modes.set.done', ['%role%' => $r->role?->name, '%modes%' => '+' . implode('', $r->values)]),RoleOutcome::ModesCleared => $c->reply('role.modes.set.cleared', ['%role%' => $r->role?->name]),RoleOutcome::InvalidModes => $c->reply('role.modes.set.invalid_modes', ['%invalid%' => '+' . implode('', $r->values), '%valid%' => '+' . implode('', $r->availableValues)]),RoleOutcome::ModesNotSupported => $c->reply('role.modes.set.no_irc_user_modes'),RoleOutcome::VhostViewed => $this->vhost($c, $r),RoleOutcome::VhostSet => $c->reply('role.vhost.set.done', ['%role%' => $r->role?->name]),RoleOutcome::VhostCleared => $c->reply('role.vhost.set.cleared', ['%role%' => $r->role?->name]),RoleOutcome::InvalidVhost => $c->reply('role.vhost.set.invalid'),RoleOutcome::OperclassesListed => $this->operclasses($c, $r),RoleOutcome::OperclassViewed => $this->operclass($c, $r),RoleOutcome::OperclassSet => $c->reply('role.operclass.set.done', ['%role%' => $r->role?->name]),RoleOutcome::OperclassCleared => $c->reply('role.operclass.set.cleared', ['%role%' => $r->role?->name]),RoleOutcome::OperclassNotAvailable => $c->reply('role.operclass.set.not_available', ['%operclass%' => $value, '%available%' => implode(', ', $r->availableValues)]),RoleOutcome::OperclassNotSupported => $c->reply('role.operclass.list.not_supported'),RoleOutcome::InvalidRequest => $c->reply('error.syntax', ['%syntax%' => $c->trans($this->getSyntaxKey())]),RoleOutcome::UnknownAction => $c->reply($this->handler->supportsOperclass() ? 'role.unknown_sub_operclass' : 'role.unknown_sub', ['%sub%' => $sub])
        };
    }

    private function roles(OperServContext $c, ManageRoleResult $r): void
    {
        if ([] === $r->roles) {
            $c->reply('role.list.empty');

            return;
        }$c->reply('role.list.header');
        foreach ($r->roles as $role) {
            $c->replyRaw(sprintf('  %-12s%-40s%s', $role->name, $role->description, $role->protected ? ' [PROTECTED]' : ''));
        }
    }

    private function permissions(OperServContext $c, ManageRoleResult $r): void
    {
        $c->reply('role.perms.list.header', ['%role%' => $r->role?->name]);
        $c->reply('role.perms.list.columns');

        $permissions = array_merge($r->values, $r->availableValues);
        sort($permissions);

        foreach ($permissions as $permission) {
            $assignedKey = in_array($permission, $r->values, true)
                ? 'role.perms.list.assigned'
                : 'role.perms.list.unassigned';

            $c->reply('role.perms.list.row', [
                '%assigned%' => $c->trans($assignedKey),
                '%permission%' => $permission,
                '%description%' => $this->permissionDescription($c, $permission),
            ]);
        }
    }

    private function permissionDescription(OperServContext $c, string $permission): string
    {
        $parts = explode('.', $permission, 2);
        if (2 !== count($parts) || '' === $parts[0] || '' === $parts[1]) {
            return $permission;
        }

        return $c->transInDomain($parts[1] . '.short', $parts[0]);
    }

    private function modes(OperServContext $c, ManageRoleResult $r): void
    {
        if ([] === $r->values) {
            $c->reply('role.modes.view.empty', ['%role%' => $r->role?->name]);

            return;
        }$c->reply('role.modes.view.header', ['%role%' => $r->role?->name]);
        $c->reply('role.modes.view.line', ['%modes%' => '+' . implode('', $r->values)]);
    }

    private function vhost(OperServContext $c, ManageRoleResult $r): void
    {
        if ([] === $r->values) {
            $c->reply('role.vhost.view.empty', ['%role%' => $r->role?->name]);

            return;
        }$c->reply('role.vhost.view.header', ['%role%' => $r->role?->name]);
        $c->reply('role.vhost.view.line', ['%pattern%' => $r->values[0]]);
        $c->reply('role.vhost.view.example', ['%pattern%' => $r->values[0]]);
    }

    private function operclasses(OperServContext $c, ManageRoleResult $r): void
    {
        if ([] === $r->values) {
            $c->reply('role.operclass.list.empty');

            return;
        }$c->reply('role.operclass.list.header');
        foreach ($r->values as $o) {
            $c->replyRaw('  ' . $o);
        }
    }

    private function operclass(OperServContext $c, ManageRoleResult $r): void
    {
        [] === $r->values ? $c->reply('role.operclass.view.empty', ['%role%' => $r->role?->name]) : $c->reply('role.operclass.view.line', ['%operclass%' => $r->values[0]]);
        if ([] !== $r->availableValues) {
            $c->reply('role.operclass.view.available', ['%available%' => implode(', ', $r->availableValues)]);
        }
    }
}
