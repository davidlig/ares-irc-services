<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Service;

use App\Application\Port\ServiceDebugNotifierInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Adapter\In\Irc\NickServNotifierInterface;
use App\NickServ\Adapter\Out\InMemory\IdentifiedSessionRegistry;
use App\NickServ\Application\Port\Out\NickAuditSink;
use App\NickServ\Application\Port\Out\NickServOperatorAccess;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class NickServDebugNotifier implements NickAuditSink, ServiceDebugNotifierInterface
{
    private const string COLOR_BLUE = "\x0302";

    private const string COLOR_RED = "\x0304";

    private const string COLOR_RESET = "\x03";

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

        $this->notify($message);
    }

    public function isIrcopOrRoot(string $nick, bool $isIdentified): bool
    {
        if (!$isIdentified) {
            return false;
        }

        if ($this->operatorAccess->isRoot($nick)) {
            return true;
        }

        $registeredNick = $this->nickRepo->findByNick($nick);

        return null !== $registeredNick
            && $this->operatorAccess->isIrcop($registeredNick->getId(), $nick);
    }

    public function getDebugChannel(): ?string
    {
        return $this->debugChannel;
    }
}
