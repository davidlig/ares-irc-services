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
use DateTimeImmutable;

use function array_slice;
use function implode;
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
        return 1;
    }

    public function getSyntaxKey(): string
    {
        return $this->handler->supportsOperclass() ? 'role.syntax_operclass' : 'role.syntax';
    }

    public function getHelpKey(): string
    {
        return $this->handler->supportsOperclass() ? 'role.help_operclass' : 'role.help';
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
        $commands = [['name' => 'LIST', 'desc_key' => 'role.list.short', 'help_key' => 'role.list.help', 'syntax_key' => 'role.list.syntax'], ['name' => 'ADD', 'desc_key' => 'role.add.short', 'help_key' => 'role.add.help', 'syntax_key' => 'role.add.syntax'], ['name' => 'DEL', 'desc_key' => 'role.del.short', 'help_key' => 'role.del.help', 'syntax_key' => 'role.del.syntax'], ['name' => 'PERMS', 'desc_key' => 'role.perms.short', 'help_key' => 'role.perms.help', 'syntax_key' => 'role.perms.syntax'], ['name' => 'MODES', 'desc_key' => 'role.modes.short', 'help_key' => 'role.modes.help', 'syntax_key' => 'role.modes.syntax'], ['name' => 'VHOST', 'desc_key' => 'role.vhost.short', 'help_key' => 'role.vhost.help', 'syntax_key' => 'role.vhost.syntax']];
        if ($this->handler->supportsOperclass()) {
            $commands[] = ['name' => 'OPERCLASS', 'desc_key' => 'role.operclass.short', 'help_key' => 'role.operclass.help', 'syntax_key' => 'role.operclass.syntax'];
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
        [$action,$role,$value,$description] = $this->parse($c, $sub);
        $r = $this->handler->handle(new ManageRole($action, $c->sender->nick, new DateTimeImmutable(), $role, $value, description: $description));
        $this->present($c, $sub, $r, $value);
    }

    /** @return array{RoleAction, string, string, string} */
    private function parse(OperServContext $c, string $sub): array
    {
        $role = strtoupper($c->args[1] ?? '');
        $verb = strtoupper($c->args[2] ?? '');
        $value = trim($c->args[3] ?? '');

        return match ($sub) {
            'ADD' => [RoleAction::Add, $role, '', trim(implode(' ', array_slice($c->args, 2)))],'DEL' => [RoleAction::Delete, $role, '', ''],'LIST' => [RoleAction::List, '', '', ''],'PERMS' => [match ($verb) {
                'LIST' => RoleAction::PermissionList,'ADD' => 'ALL' === strtoupper($value) ? RoleAction::PermissionAddAll : RoleAction::PermissionAdd,'DEL' => RoleAction::PermissionDelete,'CLEAR' => RoleAction::PermissionClear,default => RoleAction::Unknown
            }, $role, $value, ''],'MODES' => [match ($verb) {
                'VIEW' => RoleAction::ModesView,'SET' => RoleAction::ModesSet,default => RoleAction::Unknown
            }, $role, $value, ''],'VHOST' => [match ($verb) {
                'VIEW' => RoleAction::VhostView,'SET' => RoleAction::VhostSet,default => RoleAction::Unknown
            }, $role, $value, ''],'OPERCLASS' => ['LIST' === strtoupper($c->args[1] ?? '') ? RoleAction::OperclassList : match ($verb) {
                'VIEW','LIST' => RoleAction::OperclassView,'SET' => RoleAction::OperclassSet,default => RoleAction::Unknown
            }, $role, $value, ''],default => [RoleAction::Unknown, $role, $value, '']
        };
    }

    private function present(OperServContext $c, string $sub, ManageRoleResult $r, string $value): void
    {
        match ($r->outcome) {
            RoleOutcome::Added => $c->reply('role.add.done', ['%role%' => $r->role?->name]),RoleOutcome::Deleted => $c->reply('role.del.done', ['%role%' => $r->role?->name]),RoleOutcome::AlreadyExists => $c->reply('role.already_exists', ['%role%' => $r->role?->name]),RoleOutcome::NotFound => $c->reply('role.not_found', ['%role%' => strtoupper($value)]),RoleOutcome::Protected => $c->reply('role.protected', ['%role%' => $r->role?->name]),RoleOutcome::Listed => $this->roles($c, $r),RoleOutcome::PermissionsListed => $this->permissions($c, $r),RoleOutcome::PermissionAdded => $c->reply('role.perms.add.done', ['%role%' => $r->role?->name, '%perm%' => $r->values[0] ?? '']),RoleOutcome::PermissionAddedAll => $c->reply('role.perms.add.all_done', ['%role%' => $r->role?->name, '%count%' => (string) $r->count]),RoleOutcome::PermissionAlreadyAssigned => $c->reply('role.perms.already_has', ['%role%' => $r->role?->name, '%perm%' => $value]),RoleOutcome::PermissionNotFound => $c->reply('role.perms.not_found', ['%perm%' => $value]),RoleOutcome::PermissionRemoved => $c->reply('role.perms.del.done', ['%role%' => $r->role?->name, '%perm%' => $r->values[0] ?? '']),RoleOutcome::PermissionMissing => $c->reply('role.perms.does_not_have', ['%role%' => $r->role?->name, '%perm%' => $value]),RoleOutcome::PermissionsCleared => $c->reply('role.perms.clear.done', ['%role%' => $r->role?->name, '%count%' => (string) $r->count]),RoleOutcome::PermissionsEmpty => $c->reply('role.perms.clear.empty', ['%role%' => $r->role?->name]),RoleOutcome::ModesViewed => $this->modes($c, $r),RoleOutcome::ModesSet => $c->reply('role.modes.set.done', ['%role%' => $r->role?->name, '%modes%' => '+' . implode('', $r->values)]),RoleOutcome::ModesCleared => $c->reply('role.modes.set.cleared', ['%role%' => $r->role?->name]),RoleOutcome::InvalidModes => $c->reply('role.modes.set.invalid_modes', ['%invalid%' => '+' . implode('', $r->values), '%valid%' => '+' . implode('', $r->availableValues)]),RoleOutcome::ModesNotSupported => $c->reply('role.modes.set.no_irc_user_modes'),RoleOutcome::VhostViewed => $this->vhost($c, $r),RoleOutcome::VhostSet => $c->reply('role.vhost.set.done', ['%role%' => $r->role?->name]),RoleOutcome::VhostCleared => $c->reply('role.vhost.set.cleared', ['%role%' => $r->role?->name]),RoleOutcome::InvalidVhost => $c->reply('role.vhost.set.invalid'),RoleOutcome::OperclassesListed => $this->operclasses($c, $r),RoleOutcome::OperclassViewed => $this->operclass($c, $r),RoleOutcome::OperclassSet => $c->reply('role.operclass.set.done', ['%role%' => $r->role?->name]),RoleOutcome::OperclassCleared => $c->reply('role.operclass.set.cleared', ['%role%' => $r->role?->name]),RoleOutcome::OperclassNotAvailable => $c->reply('role.operclass.set.not_available', ['%operclass%' => $value, '%available%' => implode(', ', $r->availableValues)]),RoleOutcome::OperclassNotSupported => $c->reply('role.operclass.list.not_supported'),RoleOutcome::InvalidRequest => $c->reply('error.syntax', ['%syntax%' => $c->trans($this->getSyntaxKey())]),RoleOutcome::UnknownAction => $c->reply($this->handler->supportsOperclass() ? 'role.unknown_sub_operclass' : 'role.unknown_sub', ['%sub%' => $sub])
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
        foreach ($r->values as $p) {
            $c->replyRaw('  ' . $p);
        }
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
