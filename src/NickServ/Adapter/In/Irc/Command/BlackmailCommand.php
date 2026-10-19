<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\BlockedEmailDomainRepositoryInterface;
use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Domain\Entity\BlockedEmailDomain;

use function count;
use function ctype_digit;
use function strtoupper;
use function trim;

/**
 * BLACKMAIL {ADD <domain>|DEL <number|all>|LIST}; IRC parsing and presentation only.
 *
 * Maintains the e-mail domains on which user nickname registration is refused.
 * SAREGISTER is a staff-forced registration and deliberately bypasses this list.
 */
final readonly class BlackmailCommand implements NickServCommandInterface
{
    private const string SUB_ADD = 'ADD';

    private const string SUB_DEL = 'DEL';

    private const string SUB_LIST = 'LIST';

    private const string DEL_ALL = 'ALL';

    public function __construct(
        private BlockedEmailDomainRepositoryInterface $repository,
        private Clock $clock,
    ) {}

    public function getName(): string
    {
        return 'BLACKMAIL';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 0;
    }

    public function getSyntaxKey(): string
    {
        return 'blackmail.syntax';
    }

    public function getHelpKey(): string
    {
        return 'blackmail.help';
    }

    public function getExampleKey(): string
    {
        return 'blackmail.example';
    }

    public function getOrder(): int
    {
        return 75;
    }

    public function getShortDescKey(): string
    {
        return 'blackmail.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'blackmail.add.short', 'help_key' => 'blackmail.add.help', 'syntax_key' => 'blackmail.add.syntax', 'example_key' => 'blackmail.add.example'],
            ['name' => 'DEL', 'desc_key' => 'blackmail.del.short', 'help_key' => 'blackmail.del.help', 'syntax_key' => 'blackmail.del.syntax', 'example_key' => 'blackmail.del.example'],
            ['name' => 'LIST', 'desc_key' => 'blackmail.list.short', 'help_key' => 'blackmail.list.help', 'syntax_key' => 'blackmail.list.syntax', 'example_key' => 'blackmail.list.example'],
        ];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return NickServPermission::BLACKMAIL;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        return match (strtoupper($context->args[0] ?? '')) {
            self::SUB_ADD => $this->add($context),
            self::SUB_DEL => $this->delete($context),
            self::SUB_LIST => $this->listDomains($context),
            default => $this->usage($context),
        };
    }

    private function add(NickServContext $context): CommandOutcome
    {
        $rawDomain = trim($context->args[1] ?? '');
        if ('' === $rawDomain) {
            return $this->usage($context);
        }

        $domain = BlockedEmailDomain::normalize($rawDomain);
        if (!BlockedEmailDomain::isValid($domain)) {
            $context->reply('blackmail.invalid_domain', ['domain' => $rawDomain]);

            return CommandOutcome::rejected();
        }

        if ($this->repository->isBlocked($domain)) {
            $context->reply('blackmail.already_listed', ['domain' => $domain]);

            return CommandOutcome::success();
        }

        $this->repository->add(new BlockedEmailDomain($domain, $this->clock->now()));
        $context->reply('blackmail.added', ['domain' => $domain]);

        return CommandOutcome::success();
    }

    private function delete(NickServContext $context): CommandOutcome
    {
        $argument = trim($context->args[1] ?? '');
        if ('' === $argument) {
            return $this->usage($context);
        }

        if (self::DEL_ALL === strtoupper($argument)) {
            $removed = $this->repository->removeAll();
            $context->reply('blackmail.removed_all', ['count' => (string) $removed]);

            return CommandOutcome::success();
        }

        if (!ctype_digit($argument) || (int) $argument < 1) {
            $context->reply('blackmail.invalid_number', ['value' => $argument]);

            return CommandOutcome::rejected();
        }

        $removed = $this->repository->removeAtPosition((int) $argument);
        if (null === $removed) {
            $context->reply('blackmail.not_found', ['position' => $argument]);

            return CommandOutcome::rejected();
        }

        $context->reply('blackmail.removed', ['domain' => $removed->getDomain()]);

        return CommandOutcome::success();
    }

    private function listDomains(NickServContext $context): CommandOutcome
    {
        $entries = $this->repository->listAll();
        if ([] === $entries) {
            $context->reply('blackmail.list_empty');

            return CommandOutcome::success();
        }

        $context->reply('blackmail.list_header', ['count' => (string) count($entries)]);
        foreach ($entries as $offset => $entry) {
            $context->reply('blackmail.list_line', [
                'index' => (string) ($offset + 1),
                'domain' => $entry->getDomain(),
            ]);
        }

        return CommandOutcome::success();
    }

    private function usage(NickServContext $context): CommandOutcome
    {
        $context->reply('blackmail.usage', ['syntax' => $context->trans('blackmail.syntax')]);

        return CommandOutcome::rejected();
    }
}
