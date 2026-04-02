<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Service;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\DebugActionPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class OperServDebugAction implements DebugActionPort
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
        private LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->debugChannel && '' !== $this->debugChannel;
    }

    public function ensureChannelJoined(): void
    {
        if (!$this->isConfigured()) {
            return;
        }

        $this->channelActions->joinChannelAsService($this->debugChannel);
    }

    public function log(
        string $operator,
        string $command,
        string $target,
        ?string $targetHost = null,
        ?string $targetIp = null,
        ?string $reason = null,
        array $extra = [],
    ): void {
        $this->logToFile($operator, $command, $target, $targetHost, $targetIp, $reason, $extra);

        if ($this->isConfigured()) {
            $this->logToChannel($operator, $command, $target, $targetHost, $targetIp, $reason, $extra);
        }
    }

    private function logToFile(
        string $operator,
        string $command,
        string $target,
        ?string $targetHost,
        ?string $targetIp,
        ?string $reason,
        array $extra,
    ): void {
        $context = [
            'operator' => $operator,
            'command' => $command,
            'target' => $target,
        ];

        if (null !== $targetHost) {
            $context['target_host'] = $targetHost;
        }

        if (null !== $targetIp) {
            $context['target_ip'] = $targetIp;
        }

        if (null !== $reason) {
            $context['reason'] = $reason;
        }

        if ([] !== $extra) {
            $context['extra'] = $extra;
        }

        $this->logger->info($command, $context);
    }

    private function logToChannel(
        string $operator,
        string $command,
        string $target,
        ?string $targetHost,
        ?string $targetIp,
        ?string $reason,
        array $extra,
    ): void {
        $this->ensureChannelJoined();

        $coloredOperator = self::COLOR_BLUE . $operator . self::COLOR_RESET;
        $coloredCommand = self::COLOR_RED . $command . self::COLOR_RESET;
        $coloredTarget = self::COLOR_BLUE . $target . self::COLOR_RESET;

        $duration = $extra['duration'] ?? null;

        // Format reason with appropriate prefix (reason, message, or empty)
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

        // Use different translation key depending on whether duration is present
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

        $this->notifier->sendMessage($this->debugChannel, $message, 'NOTICE');
    }

    public function isIrcopOrRoot(string $nick, bool $isIdentified): bool
    {
        if ($this->rootRegistry->isRoot($nick)) {
            return true;
        }

        if (!$isIdentified) {
            return false;
        }

        $registeredNick = $this->nickRepo->findByNick($nick);

        if (null === $registeredNick) {
            return false;
        }

        $ircop = $this->ircopRepo->findByNickId($registeredNick->getId());

        return null !== $ircop;
    }

    public function getDebugChannel(): ?string
    {
        return $this->debugChannel;
    }
}
