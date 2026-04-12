<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command\Handler;

use App\Application\ChanServ\Command\ChanServCommandInterface;
use App\Application\ChanServ\Command\ChanServContext;
use App\Application\ChanServ\Security\ChanServPermission;
use App\Application\ChanServ\Service\ChannelSuspensionService;
use App\Application\Command\AuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Domain\ChanServ\Event\ChannelSuspendedEvent;
use App\Domain\ChanServ\Repository\RegisteredChannelRepositoryInterface;
use DateInterval;
use DateTimeImmutable;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function array_slice;
use function sprintf;
use function strtolower;
use function trim;

final class SuspendCommand implements ChanServCommandInterface, AuditableCommandInterface
{
    private ?IrcopAuditData $auditData = null;

    public function __construct(
        private readonly RegisteredChannelRepositoryInterface $channelRepository,
        private readonly ChannelSuspensionService $suspensionService,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function getName(): string
    {
        return 'SUSPEND';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 3;
    }

    public function getSyntaxKey(): string
    {
        return 'suspend.syntax';
    }

    public function getHelpKey(): string
    {
        return 'suspend.help';
    }

    public function getOrder(): int
    {
        return 77;
    }

    public function getShortDescKey(): string
    {
        return 'suspend.short';
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

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return false;
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

        $durationStr = $context->args[1];
        $reasonParts = array_slice($context->args, 2);
        $reason = trim(implode(' ', $reasonParts));

        if ('' === $reason) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

        $channel = $this->channelRepository->findByChannelName($channelName);

        if (null === $channel) {
            $context->reply('suspend.not_registered', ['%channel%' => $channelName]);

            return;
        }

        if ($channel->isSuspended()) {
            $context->reply('suspend.already_suspended', ['%channel%' => $channelName]);

            return;
        }

        $expiresAt = $this->parseExpiry($durationStr);

        if (null === $expiresAt && '0' !== strtolower($durationStr)) {
            $context->reply('suspend.invalid_duration');

            return;
        }

        $channel->suspend($reason, $expiresAt);
        $this->channelRepository->save($channel);

        $this->suspensionService->enforceSuspension($channel);

        $ip = $this->decodeIp($context->sender->ipBase64);
        $host = sprintf('%s@%s', $context->sender->ident, $context->sender->hostname);
        $performedByNickId = $context->senderAccount?->getId();

        $this->eventDispatcher->dispatch(new ChannelSuspendedEvent(
            channelId: $channel->getId(),
            channelName: $channel->getName(),
            channelNameLower: $channel->getNameLower(),
            reason: $reason,
            duration: '0' === strtolower($durationStr) ? null : $durationStr,
            expiresAt: $expiresAt,
            performedBy: $context->sender->nick,
            performedByNickId: $performedByNickId,
            performedByIp: $ip,
            performedByHost: $host,
        ));

        $durationDisplay = null === $expiresAt
            ? $context->trans('suspend.permanent')
            : $context->formatDate($expiresAt);

        $this->auditData = new IrcopAuditData(
            target: $channelName,
            reason: $reason,
            extra: ['duration' => $durationStr],
        );

        $context->reply('suspend.success', [
            '%channel%' => $channelName,
            '%duration%' => $durationDisplay,
        ]);
    }

    public function getAuditData(object $context): ?IrcopAuditData
    {
        return $this->auditData;
    }

    private function parseExpiry(string $expiryStr): ?DateTimeImmutable
    {
        $expiryStr = strtolower(trim($expiryStr));

        if ('0' === $expiryStr) {
            return null;
        }

        $matches = [];

        if (!preg_match('/^(\d+)([dhm])$/', $expiryStr, $matches)) {
            return null;
        }

        $value = (int) $matches[1];
        $unit = $matches[2];
        $intervalSpec = match ($unit) {
            'd' => "P{$value}D",
            'h' => "PT{$value}H",
            'm' => "PT{$value}M",
        };

        return (new DateTimeImmutable())->add(new DateInterval($intervalSpec));
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
