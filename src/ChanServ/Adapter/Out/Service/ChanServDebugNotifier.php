<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Service;

use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Application\Port\Out\ChanAuditSink;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ChanServDebugNotifier implements ChanAuditSink, ServiceDebugNotifierInterface
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
    ) {}

    public function getServiceName(): string
    {
        return 'chanserv';
    }

    public function isConfigured(): bool
    {
        return null !== $this->debugChannel && '' !== $this->debugChannel;
    }

    public function ensureChannelJoined(): void {}

    public function notify(string $message): void
    {
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

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
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

        $coloredOperator = self::COLOR_BLUE . $operator . self::COLOR_RESET;
        $decision = $extra['decision'] ?? null;
        $decisionLabel = match (true) {
            'CREG' === $command && 'accepted' === $decision => ' ACCEPT',
            'CREG' === $command && 'rejected' === $decision => ' REJECT',
            default => '',
        };
        $coloredCommand = self::COLOR_RED . $command . $decisionLabel . self::COLOR_RESET;
        $coloredTarget = self::COLOR_BLUE . $target . self::COLOR_RESET;

        $option = $extra['option'] ?? null;
        $value = $extra['value'] ?? null;
        $isFounderAction = !empty($extra['founder_action']);

        $registrationKey = match ('REGISTER' === $command ? ($extra['outcome'] ?? null) : null) {
            'registered' => 'debug.registration_completed',
            'awaiting_approval' => 'debug.registration_pending',
            default => null,
        };
        $translationKey = $registrationKey ?? 'debug.action_message';
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
                'common',
                $this->defaultLanguage,
            );
        } elseif (null !== $reason && '' !== $reason) {
            $messageParams['%reason%'] = $this->translator->trans(
                'debug.prefix_reason',
                ['%reason%' => $reason],
                'common',
                $this->defaultLanguage,
            );
        }

        $message = $this->translator->trans(
            $translationKey,
            $messageParams,
            null === $registrationKey ? 'common' : 'chanserv',
            $this->defaultLanguage,
        );

        $this->notify($message);
    }
}
