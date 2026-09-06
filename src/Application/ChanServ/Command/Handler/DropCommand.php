<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChanDropService;
use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use App\NickServ\Application\Port\Out\AuthorizationCheckerInterface;

use function assert;
use function strcasecmp;

final class DropCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly ChanDropService $dropService,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {}

    public function getName(): string
    {
        return 'DROP';
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
        return 'drop.syntax';
    }

    public function getHelpKey(): string
    {
        return 'drop.help';
    }

    public function getOrder(): int
    {
        return 75;
    }

    public function getShortDescKey(): string
    {
        return 'drop.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return ChanServPermission::DROP;
    }

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(ChanServContext $context): CommandOutcome
    {
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        $validation = $this->validateDrop($context);
        if (null === $validation) {
            return CommandOutcome::rejected();
        }

        return $this->performDrop($context, ...$validation);
    }

    /** @return array{string, RegisteredChannel, bool}|null */
    private function validateDrop(ChanServContext $context): ?array
    {
        $channelName = $context->getChannelNameArg(0);
        $force = isset($context->args[1]) && 0 === strcasecmp($context->args[1], 'force');

        if (null === $channelName) {
            $context->reply('drop.invalid_channel');

            return null;
        }

        $channel = $this->channelRepository->findByChannelName($channelName);

        if (null === $channel) {
            $context->reply('drop.not_registered', ['%channel%' => $channelName]);

            return null;
        }

        return $this->validateDropAccess($context, $channel, $channelName, $force);
    }

    /** @return array{string, RegisteredChannel, bool}|null */
    private function validateDropAccess(ChanServContext $context, RegisteredChannel $channel, string $channelName, bool $force): ?array
    {
        if ($channel->isPendingDeletion() && !$force) {
            $context->reply('drop.pending_deletion', ['%channel%' => $channelName]);

            return null;
        }

        if ($force && (null === $this->authorizationChecker || !$this->authorizationChecker->isGranted(ChanServPermission::DROP_FORCE, $context))) {
            $context->reply('error.permission_denied');

            return null;
        }

        return [$channelName, $channel, $force];
    }

    private function performDrop(ChanServContext $context, string $channelName, RegisteredChannel $channel, bool $force): CommandOutcome
    {
        $sender = $context->sender;
        assert(null !== $sender);

        if ($force) {
            $this->dropService->hardDropChannel($channel, 'manual-force', $sender->nick);
            $context->reply('drop.force_success', ['%channel%' => $channelName]);

            return CommandOutcome::success(new IrcopAuditData(target: $channelName, extra: ['force' => true]));
        }

        $this->dropService->softDropChannel($channel, $sender->nick);

        $context->reply('drop.success', ['%channel%' => $channelName]);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName));
    }
}
