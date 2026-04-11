<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Domain\ChanServ\Event\ChannelUnsuspendedEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

final class UnsuspendCommand implements ChanServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

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
        return 77;
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

    public function getRequiredPermission(): ?string
    {
        return ChanServPermission::SUSPEND;
    }

    public function allowsSuspendedChannel(): bool
    {
        return true;
    }

    public function execute(ChanServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $channelName = $context->getChannelNameArg(0);

        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        $channel = $this->channelRepository->findByChannelName($channelName);

        if (null === $channel) {
            $context->reply('unsuspend.not_registered', ['%channel%' => $channelName]);

            return;
        }

        if (!$channel->isSuspended()) {
            $context->reply('unsuspend.not_suspended', ['%channel%' => $channelName]);

            return;
        }

        $channel->unsuspend();
        $this->channelRepository->save($channel);

        $ip = $this->decodeIp($context->sender->ipBase64);
        $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new ChannelUnsuspendedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            channelNameLower: $channel->getNameLower(),
            performedBy: $context->sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $this->auditData = new IrcopAuditData(
            target: $channelName,
        );

        $context->reply('unsuspend.success', ['%channel%' => $channelName]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
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
