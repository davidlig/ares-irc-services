<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\UseCase\ManageIrcop\IrcopAction;
use App\OperServ\Application\UseCase\ManageIrcop\IrcopOutcome;
use App\OperServ\Application\UseCase\ManageIrcop\ManageIrcop;
use App\OperServ\Application\UseCase\ManageIrcop\ManageIrcopHandlerInterface;
use App\OperServ\Application\UseCase\ManageIrcop\ManageIrcopResult;
use DateTimeImmutable;

use function count;
use function in_array;
use function sprintf;
use function strtoupper;

final readonly class IrcopCommand implements OperServCommandInterface
{
    public function __construct(private ManageIrcopHandlerInterface $handler) {}

    public function getName(): string
    {
        return 'IRCOP';
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
        return 'ircop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'ircop.help';
    }

    public function getOrder(): int
    {
        return 1;
    }

    public function getShortDescKey(): string
    {
        return 'ircop.short';
    }

    public function getSubCommandHelp(): array
    {
        return [['name' => 'ADD', 'desc_key' => 'ircop.add.short', 'help_key' => 'ircop.add.help', 'syntax_key' => 'ircop.add.syntax'], ['name' => 'DEL', 'desc_key' => 'ircop.del.short', 'help_key' => 'ircop.del.help', 'syntax_key' => 'ircop.del.syntax'], ['name' => 'LIST', 'desc_key' => 'ircop.list.short', 'help_key' => 'ircop.list.help', 'syntax_key' => 'ircop.list.syntax']];
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
        }$first = strtoupper($c->args[0] ?? '');
        $action = match ($first) {
            'ADD' => IrcopAction::Add,'DEL' => IrcopAction::Delete,'LIST' => IrcopAction::List,default => IrcopAction::Unknown
        };
        $nick = $c->args[1] ?? '';
        $role = $c->args[2] ?? '';
        if (IrcopAction::Unknown === $action) {
            $legacy = strtoupper($c->args[1] ?? '');
            if (in_array($legacy, ['ADD', 'DEL'], true)) {
                $action = 'ADD' === $legacy ? IrcopAction::Add : IrcopAction::Delete;
                $nick = $c->args[0] ?? '';
                $role = $c->args[2] ?? '';
            } elseif (count($c->args) < 2) {
                $this->syntax($c);

                return;
            }
        }$r = $this->handler->handle(new ManageIrcop($action, $c->sender->nick, $c->senderAccountId(), new DateTimeImmutable(), $nick, strtoupper($role)));
        $this->present($c, $first, $r);
    }

    private function syntax(OperServContext $c): void
    {
        $c->reply('error.syntax', ['%syntax%' => $c->trans('ircop.syntax')]);
    }

    private function present(OperServContext $c, string $sub, ManageIrcopResult $r): void
    {
        match ($r->outcome) {
            IrcopOutcome::Added => $c->reply('ircop.add.done', ['%nickname%' => $r->nickname, '%role%' => $r->role]),IrcopOutcome::RoleChanged => $c->reply('ircop.role_changed', ['%nickname%' => $r->nickname, '%old%' => $r->oldRole, '%new%' => $r->role]),IrcopOutcome::Deleted => $c->reply('ircop.del.done', ['%nickname%' => $r->nickname]),IrcopOutcome::NickNotRegistered => $c->reply('error.nick_not_registered', ['%nickname%' => $r->nickname]),IrcopOutcome::NickNotActive => $c->reply('ircop.nick_not_active', ['%nickname%' => $r->nickname]),IrcopOutcome::RoleNotFound => $c->reply('ircop.unknown_role', ['%role%' => $r->role, '%bot%' => $c->getBotName()]),IrcopOutcome::AlreadyAssigned => $c->reply('ircop.already_admin', ['%nickname%' => $r->nickname, '%role%' => $r->role]),IrcopOutcome::NotAssigned => $c->reply('ircop.not_admin', ['%nickname%' => $r->nickname]),IrcopOutcome::InvalidRequest => $this->syntax($c),IrcopOutcome::UnknownAction => $c->reply('ircop.unknown_sub', ['%sub%' => $sub]),IrcopOutcome::Listed => $this->list($c, $r)
        };
    }

    private function list(OperServContext $c, ManageIrcopResult $r): void
    {
        if ([] === $r->entries) {
            $c->reply('ircop.list.empty');

            return;
        }$c->reply('ircop.list.header');
        foreach ($r->entries as $e) {
            $c->replyRaw(sprintf('  %-20s %-10s %s', $e->nickname, $e->role, $c->formatDate($e->addedAt)));
        }
    }
}
