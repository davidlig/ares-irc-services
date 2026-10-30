<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Service;

use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Application\Port\Out\ChanAuditSink;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function str_contains;
use function strtr;

final readonly class ChanServDebugNotifier implements ChanAuditSink, ServiceDebugNotifierInterface
{
    private const string PASSWORD_OPTION = 'PASSWORD';

    public function __construct(
        private ChanServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private string $defaultLanguage,
        private ?string $debugChannel,
        private IrcRenderer $renderer,
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

        $coloredOperator = new Fragment($operator, Component::Actor);
        $decision = $extra['decision'] ?? null;
        $decisionLabel = match (true) {
            'CREG' === $command && 'accepted' === $decision => ' ACCEPT',
            'CREG' === $command && 'rejected' === $decision => ' REJECT',
            default => '',
        };
        $coloredCommand = new Fragment($command . $decisionLabel, Component::ModeChange);
        $coloredTarget = new Fragment($target, Component::Actor);

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
            $messageParams['%reason%'] = new Fragment($this->translator->trans('debug.prefix_reason', [], 'common', $this->defaultLanguage), parameters: ['%reason%' => $this->translator->trans('debug.founder_action', [], 'chanserv', $this->defaultLanguage)]);
        } elseif (null !== $reason && '' !== $reason) {
            $messageParams['%reason%'] = new Fragment($this->translator->trans(
                'debug.prefix_reason',
                [],
                'common',
                $this->defaultLanguage,
            ), parameters: ['%reason%' => $reason]);
        }

        $message = $this->render($translationKey, null === $registrationKey ? 'common' : 'chanserv', $messageParams);

        $this->notify($message);
    }

    /** @param array<string, mixed> $parameters */
    private function render(string $key, string $domain, array $parameters): string
    {
        $template = $this->translator->trans($key, [], $domain, $this->defaultLanguage);
        foreach ($parameters as $name => $value) {
            if ($value instanceof Fragment && in_array($name, ['%operator%', '%command%', '%target%', '%reason%'], true)) {
                continue;
            }
            if ($value instanceof TranslatableInterface) {
                $value = $value->trans($this->translator, $this->defaultLanguage);
            }
            $parameters[$name] = str_contains($template, $name) ? strtr($name, [$name => $value]) : '';
        }

        return $this->renderer->component(Component::Text, $template, $parameters);
    }
}
