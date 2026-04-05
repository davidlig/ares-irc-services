<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Service;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\OperServ\RootUserRegistry;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\ServiceDebugNotifierInterface;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class NickServDebugNotifier implements ServiceDebugNotifierInterface
{
    private const string COLOR_BLUE = "\x0302";

    private const string COLOR_RED = "\x0304";

    private const string COLOR_RESET = "\x03";

    private const string PASSWORD_OPTION = 'PASSWORD';

    public function __construct(
        private readonly NickServNotifierInterface $notifier,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly IdentifiedSessionRegistry $identifiedRegistry,
        private readonly OperIrcopRepositoryInterface $ircopRepo,
        private readonly RootUserRegistry $rootRegistry,
        private readonly RegisteredNickRepositoryInterface $nickRepo,
        private readonly TranslatorInterface $translator,
        private readonly string $defaultLanguage,
        private readonly ?string $debugChannel,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getServiceName(): string
    {
        return 'nickserv';
    }

    public function isConfigured(): bool
    {
        return null !== $this->debugChannel && '' !== $this->debugChannel;
    }

    public function ensureChannelJoined(): void
    {
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
        $coloredOperator = self::COLOR_BLUE . $operator . self::COLOR_RESET;
        $coloredCommand = self::COLOR_RED . $command . self::COLOR_RESET;
        $coloredTarget = self::COLOR_BLUE . $target . self::COLOR_RESET;

        $duration = $extra['duration'] ?? null;
        $option = $extra['option'] ?? null;
        $value = $extra['value'] ?? null;

        $formattedReason = '';
        if (null !== $reason && '' !== $reason) {
            $formattedReason = $this->translator->trans(
                'debug.prefix_reason',
                ['%reason%' => $reason],
                'nickserv',
                $this->defaultLanguage,
            );
        }

        $translationKey = 'debug.action_message';
        $messageParams = [
            '%operator%' => $coloredOperator,
            '%command%' => $coloredCommand,
            '%target%' => $coloredTarget,
            '%reason%' => $formattedReason,
        ];

        if (null !== $option) {
            if (self::PASSWORD_OPTION === $option) {
                $messageParams['%option%'] = $option;
                $translationKey = 'debug.action_with_option';
            } elseif (null !== $value) {
                $messageParams['%option%'] = $option;
                $messageParams['%value%'] = $value;
                $translationKey = 'debug.action_with_value';
            } else {
                $messageParams['%option%'] = $option;
                $translationKey = 'debug.action_with_option';
            }
        }

        if (null !== $duration && '' !== $duration) {
            $messageParams['%duration%'] = $duration;
            $messageParams['%reason%'] = $formattedReason;
            $translationKey = 'debug.action_duration';
        }

        $message = $this->translator->trans(
            $translationKey,
            $messageParams,
            'nickserv',
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
