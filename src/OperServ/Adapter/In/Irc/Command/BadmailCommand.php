<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc\Command;

use App\OperServ\Adapter\In\Irc\OperServCommandInterface;
use App\OperServ\Adapter\In\Irc\OperServContext;
use App\OperServ\Application\Security\OperServPermission;
use App\OperServ\Application\UseCase\Badmail\BadmailAction;
use App\OperServ\Application\UseCase\Badmail\BadmailOutcome;
use App\OperServ\Application\UseCase\Badmail\ManageBadmail;
use App\OperServ\Application\UseCase\Badmail\ManageBadmailHandler;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use DateTimeImmutable;

use function array_slice;
use function ceil;
use function count;
use function filter_var;
use function implode;
use function max;
use function strtoupper;
use function trim;

final readonly class BadmailCommand implements OperServCommandInterface
{
    public function __construct(private ManageBadmailHandler $handler) {}

    public function getName(): string
    {
        return 'BADMAIL';
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
        return new CommandArgumentDefinition('badmail.syntax', [new Argument('action', alternatives: ['ADD', 'DEL', 'LIST'])], [
            'ADD' => new CommandArgumentDefinition('badmail.add.syntax', [
                new Argument('domain'),
                new Argument('reason', rest: true),
            ]),
            'DEL' => new CommandArgumentDefinition('badmail.del.syntax', [new Argument('id')], allowExtra: false),
            'LIST' => new CommandArgumentDefinition('badmail.list.syntax', [
                new Argument('pattern'),
                new Argument('page', optional: true),
            ], allowExtra: false),
        ]);
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    public function getHelpKey(): string
    {
        return 'badmail.help';
    }

    public function getExampleKey(): string
    {
        return 'badmail.example';
    }

    public function getOrder(): int
    {
        return 73;
    }

    public function getShortDescKey(): string
    {
        return 'badmail.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'badmail.add.short', 'help_key' => 'badmail.add.help', 'syntax_key' => 'badmail.add.syntax', 'example_key' => 'badmail.add.example'],
            ['name' => 'DEL', 'desc_key' => 'badmail.del.short', 'help_key' => 'badmail.del.help', 'syntax_key' => 'badmail.del.syntax', 'example_key' => 'badmail.del.example'],
            ['name' => 'LIST', 'desc_key' => 'badmail.list.short', 'help_key' => 'badmail.list.help', 'syntax_key' => 'badmail.list.syntax', 'example_key' => 'badmail.list.example'],
        ];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return OperServPermission::BADMAIL;
    }

    /** @return array<string, string> */
    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(OperServContext $context): void
    {
        $sender = $context->sender;
        if (null === $sender) {
            return;
        }
        $validation = CommandArgumentValidator::validate($this->getArgumentDefinition(), $context->args);
        if ($validation->recognized && !$validation->isValid()) {
            $this->syntax($context, $validation->syntaxKey ?? $this->getSyntaxKey());

            return;
        }

        $authorized = $context->isAuthorized(OperServPermission::BADMAIL);
        $input = $this->parse($context->args, $authorized, $sender->nick, new DateTimeImmutable());
        if (null === $input) {
            $this->syntax($context, $this->syntaxKeyForArguments($context->args));

            return;
        }
        $result = $this->handler->handle($input);
        $error = match ($result->outcome) {
            BadmailOutcome::Denied => 'error.permission_denied',
            BadmailOutcome::InvalidDomain => 'badmail.invalid_domain',
            BadmailOutcome::InvalidReason => 'badmail.invalid_reason',
            BadmailOutcome::Duplicate => 'badmail.duplicate',
            BadmailOutcome::NotFound => 'badmail.not_found',
            default => null,
        };
        if (null !== $error) {
            $context->reply($error);

            return;
        }
        if (BadmailOutcome::Listed === $result->outcome) {
            $context->reply('badmail.header');
            foreach ($result->entries as $entry) {
                $context->reply('badmail.row', ['id' => $entry->id, 'domain' => $entry->domain, 'reason' => $entry->reason]);
            }
            if (0 === $result->total) {
                $context->reply('badmail.empty');
            }
            $context->reply('badmail.page', ['page' => $result->page, 'pages' => (int) ceil($result->total / $result->pageSize), 'total' => $result->total]);
        } else {
            $context->reply(match ($result->outcome) {
                BadmailOutcome::Added => 'badmail.added',
                BadmailOutcome::Deleted => 'badmail.deleted',
                default => 'badmail.cleared',
            }, ['domain' => $result->domain, 'id' => $input->id, 'total' => $result->total]);
        }
    }

    /** @param list<string> $args */
    private function syntaxKeyForArguments(array $args): string
    {
        return $this->getArgumentDefinition()->variants[strtoupper($args[0] ?? '')]->syntaxKey ?? $this->getSyntaxKey();
    }

    private function syntax(OperServContext $context, string $syntaxKey): void
    {
        $context->reply('error.syntax', ['syntax' => $context->trans($syntaxKey)]);
    }

    /** @param list<string> $args */
    private function parse(array $args, bool $authorized, string $actor, DateTimeImmutable $occurredAt): ?ManageBadmail
    {
        $sub = strtoupper($args[0] ?? '');
        if ('ADD' === $sub && 3 <= count($args)) {
            return new ManageBadmail(BadmailAction::Add, $authorized, $actor, $occurredAt, domain: $args[1], reason: implode(' ', array_slice($args, 2)));
        }
        if ('DEL' === $sub && 2 === count($args)) {
            if ('ALL' === strtoupper($args[1])) {
                return new ManageBadmail(BadmailAction::Clear, $authorized, $actor, $occurredAt);
            }
            $id = filter_var($args[1], FILTER_VALIDATE_INT);

            return false !== $id && 0 < $id ? new ManageBadmail(BadmailAction::Delete, $authorized, $actor, $occurredAt, id: $id) : null;
        }
        if ('LIST' === $sub && 2 <= count($args) && 3 >= count($args) && '' !== trim($args[1])) {
            $page = isset($args[2]) ? filter_var($args[2], FILTER_VALIDATE_INT) : 1;

            return false !== $page ? new ManageBadmail(BadmailAction::List, $authorized, $actor, $occurredAt, pattern: $args[1], page: max(1, $page)) : null;
        }

        return null;
    }
}
