<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Service;

use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class OperServDebugNotifier implements ServiceDebugNotifierInterface
{
    private const string COLOR_BLUE = "\x0302";

    private const string COLOR_RED = "\x0304";

    private const string COLOR_RESET = "\x03";

    public function __construct(
        private ChannelServiceActionsPort $channelActions,
        private NetworkUserLookupPort $userLookup,
        private OperServNotifierInterface $notifier,
        private IdentifiedSessionRegistry $identifiedRegistry,
        private OperIrcopRepositoryInterface $ircopRepo,
        private RootUserRegistry $rootRegistry,
        private RegisteredNickRepositoryInterface $nickRepo,
        private TranslatorInterface $translator,
        private string $defaultLanguage,
        private ?string $debugChannel,
    ) {}

    public function getUserLookup(): NetworkUserLookupPort
    {
        return $this->userLookup;
    }

    public function getIdentifiedRegistry(): IdentifiedSessionRegistry
    {
        return $this->identifiedRegistry;
    }

    public function getServiceName(): string
    {
        return 'operserv';
    }

    public function isConfigured(): bool
    {
        return null !== $this->debugChannel && '' !== $this->debugChannel;
    }

    public function ensureChannelJoined(): void
    {
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

        $this->channelActions->joinChannelAsService($this->debugChannel);
    }

    public function notify(string $message): void
    {
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

        $this->ensureChannelJoined();
        $this->notifier->sendMessage($this->debugChannel, $message, 'NOTICE');
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function log(
        string $operator,
        string $command,
        string $target,
        ?string $targetHost = null,
        ?string $targetIp = null,
        ?string $reason = null,
        array $extra = [],
    ): void {
        if ($this->isConfigured()) {
            $this->logToChannel($operator, $command, $target, $targetHost, $targetIp, $reason, $extra);
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function logToChannel(
        string $operator,
        string $command,
        string $target,
        ?string $targetHost,
        ?string $targetIp,
        ?string $reason,
        array $extra,
    ): void {
        $coloredOperator = self::COLOR_BLUE . $operator . self::COLOR_RESET;
        $coloredCommand = self::COLOR_RED . $command . self::COLOR_RESET;
        $coloredTarget = self::COLOR_BLUE . $target . self::COLOR_RESET;

        $duration = $extra['duration'] ?? null;

        if ('GLOBAL' === $command) {
            $messageParams = [
                '%operator%' => $coloredOperator,
                '%command%' => $coloredCommand,
                '%target%' => $coloredTarget,
                '%type%' => $extra['type'] ?? 'PRIVMSG',
                '%count%' => $extra['count'] ?? '0',
                '%message%' => $reason ?? '',
            ];

            $message = $this->translator->trans(
                'debug.action_global',
                $messageParams,
                'operserv',
                $this->defaultLanguage,
            );
        } else {
            $formattedReason = '';
            if (null !== $reason && '' !== $reason) {
                $reasonType = $extra['reasonType'] ?? 'reason';
                $prefixKey = 'reason' === $reasonType ? 'debug.prefix_reason' : 'debug.prefix_message';
                $formattedReason = $this->translator->trans(
                    $prefixKey,
                    ['%reason%' => $reason],
                    'operserv',
                    $this->defaultLanguage,
                );
            }

            $messageParams = [
                '%operator%' => $coloredOperator,
                '%command%' => $coloredCommand,
                '%target%' => $coloredTarget,
                '%reason%' => $formattedReason,
            ];

            $translationKey = null !== $duration && '' !== $duration
                ? 'debug.actionWithDuration'
                : 'debug.action_message';

            if (null !== $duration && '' !== $duration) {
                $messageParams['%duration%'] = $duration;
            }

            $message = $this->translator->trans(
                $translationKey,
                $messageParams,
                'operserv',
                $this->defaultLanguage,
            );
        }

        $this->notify($message);
    }

    public function isIrcopOrRoot(string $nick, bool $isIdentified): bool
    {
        if (!$isIdentified) {
            return false;
        }

        $result = $this->rootRegistry->isRoot($nick);

        if (!$result) {
            $registeredNick = $this->nickRepo->findByNick($nick);
            $result = null !== $registeredNick && null !== $this->ircopRepo->findByNickId($registeredNick->getId());
        }

        return $result;
    }

    public function getDebugChannel(): ?string
    {
        return $this->debugChannel;
    }
}
