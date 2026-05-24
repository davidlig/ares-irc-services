<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

use function in_array;
use function strtoupper;

final class NoexpireCommand implements ChanServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
    ) {
    }

    public function getName(): string
    {
        return 'NOEXPIRE';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'noexpire.syntax';
    }

    public function getHelpKey(): string
    {
        return 'noexpire.help';
    }

    public function getOrder(): int
    {
        return 76;
    }

    public function getShortDescKey(): string
    {
        return 'noexpire.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return ChanServPermission::NOEXPIRE;
    }

    public function allowsSuspendedChannel(): bool
    {
        return false;
    }

    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return false;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(ChanServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $validation = $this->validateNoexpire($context);
        if (null === $validation) {
            return;
        }

        $this->performNoexpire($context, ...$validation);
    }

    /** @return array{string, object, bool}|null */
    private function validateNoexpire(ChanServContext $context): ?array
    {
        $channelName = $context->getChannelNameArg(0);

        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return null;
        }

        $action = strtoupper($context->args[1]);

        if (!in_array($action, ['ON', 'OFF'], true)) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return null;
        }

        return $this->validateNoexpireChannel($context, $channelName, $action);
    }

    /** @return array{string, object, bool}|null */
    private function validateNoexpireChannel(ChanServContext $context, string $channelName, string $action): ?array
    {
        $channel = $this->channelRepository->findByChannelName($channelName);

        if (null === $channel) {
            $context->reply('noexpire.not_registered', ['%channel%' => $channelName]);

            return null;
        }

        if ($channel->isForbidden()) {
            $context->reply('noexpire.forbidden', ['%channel%' => $channelName]);

            return null;
        }

        return $this->validateNoexpireSuspended($context, $channel, $channelName, $action);
    }

    /** @return array{string, object, bool}|null */
    private function validateNoexpireSuspended(ChanServContext $context, object $channel, string $channelName, string $action): ?array
    {
        if ($channel->isSuspended()) {
            $context->reply('noexpire.suspended', ['%channel%' => $channelName]);

            return null;
        }

        $newValue = 'ON' === $action;

        return [$channelName, $channel, $newValue];
    }

    private function performNoexpire(ChanServContext $context, string $channelName, object $channel, bool $newValue): void
    {
        $channel->setNoExpire($newValue);
        $this->channelRepository->save($channel);

        $action = $newValue ? 'ON' : 'OFF';
        $this->auditData = new IrcopAuditData(
            target: $channelName,
            extra: ['option' => $action],
        );

        $context->reply(
            $newValue ? 'noexpire.success_on' : 'noexpire.success_off',
            ['%channel%' => $channelName],
        );
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }
}
