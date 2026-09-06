<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Application\Service\ForbiddenPatternValidator;
use App\NickServ\Application\Service\ForbiddenVhostService;
use Psr\Log\LoggerInterface;

use function assert;
use function count;
use function sprintf;
use function strtoupper;
use function trim;

final class ForbidvhostCommand implements NickServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly ForbiddenVhostRepositoryInterface $forbiddenVhostRepository,
        private readonly ForbiddenVhostService $forbiddenVhostService,
        private readonly ForbiddenPatternValidator $patternValidator,
        private readonly LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'FORBIDVHOST';
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
        return 'forbidvhost.syntax';
    }

    public function getHelpKey(): string
    {
        return 'forbidvhost.help';
    }

    public function getOrder(): int
    {
        return 72;
    }

    public function getShortDescKey(): string
    {
        return 'forbidvhost.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'ADD', 'desc_key' => 'forbidvhost.add.short', 'help_key' => 'forbidvhost.add.help', 'syntax_key' => 'forbidvhost.add.syntax'],
            ['name' => 'DEL', 'desc_key' => 'forbidvhost.del.short', 'help_key' => 'forbidvhost.del.help', 'syntax_key' => 'forbidvhost.del.syntax'],
            ['name' => 'LIST', 'desc_key' => 'forbidvhost.list.short', 'help_key' => 'forbidvhost.list.help', 'syntax_key' => 'forbidvhost.list.syntax'],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return NickServPermission::FORBIDVHOST;
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

        $sub = strtoupper($context->args[0] ?? '');

        return match ($sub) {
            'ADD' => $this->doAdd($context),
            'DEL' => $this->doDel($context),
            'LIST' => $this->doList($context),
            default => $this->rejectUnknownSubcommand($context, $sub),
        };
    }

    private function rejectUnknownSubcommand(NickServContext $context, string $sub): CommandOutcome
    {
        $context->reply('forbidvhost.unknown_sub', ['%sub%' => $sub]);

        return CommandOutcome::rejected();
    }

    private function doAdd(NickServContext $context): CommandOutcome
    {
        $syntaxKey = 'forbidvhost.add.syntax';
        $pattern = count($context->args) >= 2 ? trim($context->args[1]) : '';

        if (count($context->args) < 2 || '' === $pattern) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans($syntaxKey)]);

            return CommandOutcome::rejected();
        }

        $errorKey = $this->validateAddPattern($pattern);
        if (null !== $errorKey) {
            $context->reply($errorKey, ['%pattern%' => $pattern]);

            return CommandOutcome::rejected();
        }

        return $this->executeAdd($context, $pattern);
    }

    private function validateAddPattern(string $pattern): ?string
    {
        $result = match (true) {
            !$this->patternValidator->isValid($pattern) => 'forbidvhost.add.invalid',
            null !== $this->forbiddenVhostRepository->findByPattern($pattern) => 'forbidvhost.add.already_exists',
            default => null,
        };

        return $result;
    }

    private function executeAdd(NickServContext $context, string $pattern): CommandOutcome
    {
        assert(null !== $context->sender);
        $creatorNickId = $context->senderAccount?->getId();
        $this->forbiddenVhostService->forbid($pattern, $creatorNickId);

        $this->logger->info('Vhost pattern forbidden via FORBIDVHOST ADD', [
            'operator' => $context->sender->nick,
            'pattern' => $pattern,
        ]);
        $context->reply('forbidvhost.add.done', ['%pattern%' => $pattern]);

        return CommandOutcome::success(new IrcopAuditData(target: $pattern));
    }

    private function doDel(NickServContext $context): CommandOutcome
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('forbidvhost.del.syntax')]);

            return CommandOutcome::rejected();
        }

        $pattern = trim($context->args[1]);
        if ('' === $pattern) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('forbidvhost.del.syntax')]);

            return CommandOutcome::rejected();
        }

        $removed = $this->forbiddenVhostService->unforbid($pattern);

        if (!$removed) {
            $context->reply('forbidvhost.del.not_found', ['%pattern%' => $pattern]);

            return CommandOutcome::rejected();
        }

        assert(null !== $context->sender);
        $this->logger->info('Vhost pattern unforbidden via FORBIDVHOST DEL', [
            'operator' => $context->sender->nick,
            'pattern' => $pattern,
        ]);

        $context->reply('forbidvhost.del.done', ['%pattern%' => $pattern]);

        return CommandOutcome::success(new IrcopAuditData(target: $pattern));
    }

    private function doList(NickServContext $context): CommandOutcome
    {
        $forbiddenList = $this->forbiddenVhostService->getAll();

        if ([] === $forbiddenList) {
            $context->reply('forbidvhost.list.empty');

            return CommandOutcome::rejected();
        }

        $context->reply('forbidvhost.list.header', ['%count%' => (string) count($forbiddenList)]);

        $num = 1;
        foreach ($forbiddenList as $forbidden) {
            $creatorName = $this->resolveCreatorName($forbidden->getCreatedByNickId(), $context);
            $createdAt = $context->formatDate($forbidden->getCreatedAt());

            $context->reply('forbidvhost.list.entry', [
                '%index%' => (string) $num,
                '%pattern%' => sprintf("\x0304%s\x03", $forbidden->getPattern()),
                '%nickname%' => $creatorName,
                '%date%' => $createdAt,
            ]);
            ++$num;
        }

        return CommandOutcome::rejected();
    }

    private function resolveCreatorName(?int $creatorNickId, NickServContext $context): string
    {
        if (null === $creatorNickId) {
            return $context->trans('forbidvhost.list.unknown_creator');
        }

        $creator = $context->senderAccount?->getId() === $creatorNickId
            ? $context->senderAccount
            : null;

        return null !== $creator ? $creator->getNickname() : $context->trans('forbidvhost.list.unknown_creator');
    }
}
