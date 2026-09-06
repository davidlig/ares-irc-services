<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\Port\EventBusInterface;
use App\Domain\ChanServ\Entity\RegisteredChannel;
use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;

use function assert;
use function sprintf;

final class UnsuspendCommand implements ChanServCommandInterface, IrcopAuditableCommandInterface
{
    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly EventBusInterface $eventDispatcher,
    ) {}

    public function getName(): string
    {
        return 'UNSUSPEND';
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
        return 'unsuspend.syntax';
    }

    public function getHelpKey(): string
    {
        return 'unsuspend.help';
    }

    public function getOrder(): int
    {
        return 78;
    }

    public function getShortDescKey(): string
    {
        return 'unsuspend.short';
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
        return ChanServPermission::SUSPEND;
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

    public function execute(ChanServContext $context): CommandOutcome
    {
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        $validation = $this->validateUnsuspend($context);
        if (null === $validation) {
            return CommandOutcome::rejected();
        }

        return $this->performUnsuspend($context, ...$validation);
    }

    /** @return array{string, RegisteredChannel}|null */
    private function validateUnsuspend(ChanServContext $context): ?array
    {
        $channelName = $context->getChannelNameArg(0);

        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return null;
        }

        $channel = $this->channelRepository->findByChannelName($channelName);

        if (null === $channel) {
            $context->reply('unsuspend.not_registered', ['%channel%' => $channelName]);

            return null;
        }

        return $this->checkUnsuspendStatus($context, $channel, $channelName);
    }

    /** @return array{string, RegisteredChannel}|null */
    private function checkUnsuspendStatus(ChanServContext $context, RegisteredChannel $channel, string $channelName): ?array
    {
        if (!$channel->isSuspended()) {
            $context->reply('unsuspend.not_suspended', ['%channel%' => $channelName]);

            return null;
        }

        return [$channelName, $channel];
    }

    private function performUnsuspend(ChanServContext $context, string $channelName, RegisteredChannel $channel): CommandOutcome
    {
        $sender = $context->sender;
        assert(null !== $sender);

        $channel->unsuspend();
        $this->channelRepository->save($channel);

        $ip = $this->decodeIp($sender->ipBase64);
        $host = sprintf('%s@%s', $sender->ident, $sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new ChannelUnsuspendedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            channelNameLower: $channel->getNameLower(),
            performedBy: $sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $context->reply('unsuspend.success', ['%channel%' => $channelName]);

        return CommandOutcome::success(new IrcopAuditData(target: $channelName));
    }

    private function decodeIp(string $ipBase64): string
    {
        if ('' === $ipBase64 || '*' === $ipBase64) {
            return '*';
        }

        $binary = base64_decode($ipBase64, true);

        if (false === $binary) {
            return $ipBase64;
        }

        $ip = inet_ntop($binary);

        return false !== $ip ? $ip : $ipBase64;
    }
}
