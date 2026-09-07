<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanAuthorizationCheckerInterface;
use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Application\Service\ChanDropService;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;

use function assert;
use function strcasecmp;

final class DropCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly ChanDropService $dropService,
        private readonly ?ChanAuthorizationCheckerInterface $authorizationChecker = null,
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
