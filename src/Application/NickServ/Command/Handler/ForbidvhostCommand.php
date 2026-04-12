<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\ForbiddenPatternValidator;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Service\ForbiddenVhostService;
use App\Domain\NickServ\Repository\ForbiddenVhostRepositoryInterface;
use Psr\Log\LoggerInterface;

use function count;
use function sprintf;
use function strtoupper;
use function trim;

final class ForbidvhostCommand implements NickServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly ForbiddenVhostRepositoryInterface $forbiddenVhostRepository,
        private readonly ForbiddenVhostService $forbiddenVhostService,
        private readonly ForbiddenPatternValidator $patternValidator,
        private readonly LoggerInterface $logger,
    ) {
    }

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

    public function getRequiredPermission(): ?string
    {
        return NickServPermission::FORBIDVHOST;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $sub = strtoupper($context->args[0] ?? '');

        switch ($sub) {
            case 'ADD':
                $this->doAdd($context);
                break;
            case 'DEL':
                $this->doDel($context);
                break;
            case 'LIST':
                $this->doList($context);
                break;
            default:
                $context->reply('forbidvhost.unknown_sub', ['%sub%' => $sub]);
        }
    }

    private function doAdd(NickServContext $context): void
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('forbidvhost.add.syntax')]);

            return;
        }

        $pattern = trim($context->args[1]);
        if ('' === $pattern) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('forbidvhost.add.syntax')]);

            return;
        }

        if (!$this->patternValidator->isValid($pattern)) {
            $context->reply('forbidvhost.add.invalid');

            return;
        }

        $existing = $this->forbiddenVhostRepository->findByPattern($pattern);
        if (null !== $existing) {
            $context->reply('forbidvhost.add.already_exists', ['%pattern%' => $pattern]);

            return;
        }

        $creatorNickId = $context->senderAccount?->getId();
        $this->forbiddenVhostService->forbid($pattern, $creatorNickId);

        $this->auditData = new IrcopAuditData(
            target: $pattern,
        );

        $this->logger->info('Vhost pattern forbidden via FORBIDVHOST ADD', [
            'operator' => $context->sender->nick,
            'pattern' => $pattern,
        ]);

        $context->reply('forbidvhost.add.done', ['%pattern%' => $pattern]);
    }

    private function doDel(NickServContext $context): void
    {
        if (count($context->args) < 2) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('forbidvhost.del.syntax')]);

            return;
        }

        $pattern = trim($context->args[1]);
        if ('' === $pattern) {
            $context->reply('error.syntax', ['%syntax%' => $context->trans('forbidvhost.del.syntax')]);

            return;
        }

        $removed = $this->forbiddenVhostService->unforbid($pattern);

        if (!$removed) {
            $context->reply('forbidvhost.del.not_found', ['%pattern%' => $pattern]);

            return;
        }

        $this->auditData = new IrcopAuditData(
            target: $pattern,
        );

        $this->logger->info('Vhost pattern unforbidden via FORBIDVHOST DEL', [
            'operator' => $context->sender->nick,
            'pattern' => $pattern,
        ]);

        $context->reply('forbidvhost.del.done', ['%pattern%' => $pattern]);
    }

    private function doList(NickServContext $context): void
    {
        $forbiddenList = $this->forbiddenVhostService->getAll();

        if ([] === $forbiddenList) {
            $context->reply('forbidvhost.list.empty');

            return;
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

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
