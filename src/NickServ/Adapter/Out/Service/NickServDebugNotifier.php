<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Service;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\Out\NickAuditSink;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function str_contains;
use function strtr;

final readonly class NickServDebugNotifier implements NickAuditSink, ServiceDebugNotifierInterface
{
    private const string PASSWORD_OPTION = 'PASSWORD';

    public function __construct(
        private NickServNotifierInterface $notifier,
        private NetworkUserLookupPort $userLookup,
        private IdentifiedSessionRegistry $identifiedRegistry,
        private NickServOperatorAccess $operatorAccess,
        private RegisteredNickRepositoryInterface $nickRepo,
        private TranslatorInterface $translator,
        private string $defaultLanguage,
        private ?string $debugChannel,
        private IrcRenderer $renderer,
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
        return 'nickserv';
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

    public function isIrcopOrRoot(string $nick, bool $isIdentified, bool $isIrcOperator): bool
    {
        $registeredNick = $this->nickRepo->findByNick($nick);

        return $this->operatorAccess->isIrcop(
            $nick,
            $registeredNick?->getId(),
            $isIdentified,
            $isIrcOperator,
        );
    }

    public function getDebugChannel(): ?string
    {
        return $this->debugChannel;
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
        $coloredOperator = new Fragment($operator, Component::Actor);
        $coloredCommand = new Fragment($command, Component::ModeChange);
        $coloredTarget = new Fragment($target, Component::Actor);

        $duration = $extra['duration'] ?? null;
        $option = $extra['option'] ?? null;
        $value = $extra['value'] ?? null;

        $formattedReason = '';
        if (null !== $reason && '' !== $reason) {
            $formattedReason = new Fragment($this->translator->trans(
                'debug.prefix_reason',
                [],
                'common',
                $this->defaultLanguage,
            ), parameters: ['%reason%' => $reason]);
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

        $message = $this->render($translationKey, 'common', $messageParams);

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
