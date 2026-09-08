<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\PublishedEvent\ChannelUnsuspendedEvent;
use App\ChanServ\Application\Security\ChanServPermission;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData;
use App\Shared\Application\Port\EventBusInterface;

use function assert;
use function base64_decode;
use function inet_ntop;
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
        $performedByNickId = $context->senderAccount?->id;

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
