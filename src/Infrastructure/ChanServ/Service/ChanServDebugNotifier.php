<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Service;

use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\ServiceDebugNotifierInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ChanServDebugNotifier implements ServiceDebugNotifierInterface
{
    private const string COLOR_BLUE = "\x0302";

    private const string COLOR_RED = "\x0304";

    private const string COLOR_RESET = "\x03";

    public function __construct(
        private ChanServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private string $defaultLanguage,
        private ?string $debugChannel,
        private LoggerInterface $logger,
    ) {
    }

    public function getServiceName(): string
    {
        return 'chanserv';
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
            $this->logToChannel($operator, $command, $target, $reason);
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
        ?string $reason,
    ): void {
        $coloredOperator = self::COLOR_BLUE . $operator . self::COLOR_RESET;
        $coloredCommand = self::COLOR_RED . $command . self::COLOR_RESET;
        $coloredTarget = self::COLOR_BLUE . $target . self::COLOR_RESET;

        $formattedReason = '';
        if (null !== $reason && '' !== $reason) {
            $formattedReason = $this->translator->trans(
                'debug.prefix_reason',
                ['%reason%' => $reason],
                'chanserv',
                $this->defaultLanguage,
            );
        }

        $message = $this->translator->trans(
            'debug.action_message',
            [
                '%operator%' => $coloredOperator,
                '%command%' => $coloredCommand,
                '%target%' => $coloredTarget,
                '%reason%' => $formattedReason,
            ],
            'chanserv',
            $this->defaultLanguage,
        );

        $this->notifier->sendMessage($this->debugChannel, $message, 'NOTICE');
    }
}
