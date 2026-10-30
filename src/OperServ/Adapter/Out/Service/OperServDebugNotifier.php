<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Service;

use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function str_contains;
use function strtr;

final readonly class OperServDebugNotifier implements ServiceDebugNotifierInterface
{
    public function __construct(
        private ChannelServiceActionsPort $channelActions,
        private NetworkUserLookupPort $userLookup,
        private OperServNotifierInterface $notifier,
        private NickAccountQuery $nickAccounts,
        private OperatorAuthorizationQuery $authorization,
        private TranslatorInterface $translator,
        private string $defaultLanguage,
        private ?string $debugChannel,
        private IrcRenderer $renderer,
    ) {}

    public function getUserLookup(): NetworkUserLookupPort
    {
        return $this->userLookup;
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

    public function isIrcopOrRoot(string $nick, bool $isIdentified, bool $isIrcOperator): bool
    {
        $accountId = $isIdentified ? $this->nickAccounts->findIdByNick($nick) : null;

        return $this->authorization->ircOperator(new OperatorActor(
            $nick,
            $accountId,
            $isIdentified,
            $isIrcOperator,
        ))->granted;
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

        if ('GLOBAL' === $command) {
            $messageParams = [
                '%operator%' => $coloredOperator,
                '%command%' => $coloredCommand,
                '%target%' => $coloredTarget,
                '%type%' => $extra['type'] ?? 'PRIVMSG',
                '%count%' => $extra['count'] ?? '0',
                '%message%' => $reason ?? '',
            ];

            $message = $this->render('debug.action_global', 'operserv', $messageParams);
        } else {
            $formattedReason = '';
            if (null !== $reason && '' !== $reason) {
                $reasonType = $extra['reasonType'] ?? 'reason';
                $prefixKey = 'reason' === $reasonType ? 'debug.prefix_reason' : 'debug.prefix_message';
                $formattedReason = new Fragment($this->translator->trans(
                    $prefixKey,
                    [],
                    'reason' === $reasonType ? 'common' : 'operserv',
                    $this->defaultLanguage,
                ), parameters: ['%reason%' => $reason]);
            }

            $messageParams = [
                '%operator%' => $coloredOperator,
                '%command%' => $coloredCommand,
                '%target%' => $coloredTarget,
                '%reason%' => $formattedReason,
            ];

            $translationKey = null !== $duration && '' !== $duration
                ? 'debug.action_duration'
                : 'debug.action_message';

            if (null !== $duration && '' !== $duration) {
                $messageParams['%duration%'] = $duration;
            }

            $message = $this->render($translationKey, 'common', $messageParams);
        }

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
