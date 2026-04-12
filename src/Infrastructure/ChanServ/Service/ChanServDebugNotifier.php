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

    private const string PASSWORD_OPTION = 'PASSWORD';

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

        $option = $extra['option'] ?? null;
        $value = $extra['value'] ?? null;
        $isFounderAction = !empty($extra['founder_action']);

        $translationKey = 'debug.action_message';
        $messageParams = [
            '%operator%' => $coloredOperator,
            '%command%' => $coloredCommand,
            '%target%' => $coloredTarget,
            '%reason%' => '',
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
        } elseif ($isFounderAction) {
            $messageParams['%reason%'] = $this->translator->trans(
                'debug.prefix_reason',
                ['%reason%' => $this->translator->trans('debug.founder_action', [], 'chanserv', $this->defaultLanguage)],
                'chanserv',
                $this->defaultLanguage,
            );
        } elseif (null !== $reason && '' !== $reason) {
            $messageParams['%reason%'] = $this->translator->trans(
                'debug.prefix_reason',
                ['%reason%' => $reason],
                'chanserv',
                $this->defaultLanguage,
            );
        }

        $message = $this->translator->trans(
            $translationKey,
            $messageParams,
            'chanserv',
            $this->defaultLanguage,
        );

        $this->notifier->sendMessage($this->debugChannel, $message, 'NOTICE');
    }
}
