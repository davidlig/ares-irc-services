<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command;

use App\Application\NickServ\PendingVerificationRegistry;
use App\Application\NickServ\RecoveryTokenRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Carries all the context a NickServ command needs to execute.
 *
 * Commands send replies via reply() / replyLines() rather than directly
 * touching the connection, which keeps them decoupled from IRC transport.
 *
 * The timezone set here (user preference or default) applies to all date/time
 * display: use formatDate() whenever showing a date or time to the user.
 */
readonly class NickServContext
{
    public function __construct(
        public readonly ?SenderView $sender,
        public readonly ?RegisteredNick $senderAccount,
        public readonly string $command,
        /** @var string[] */
        public readonly array $args,
        private readonly NickServNotifierInterface $notifier,
        private readonly TranslatorInterface $translator,
        private readonly string $language,
        /** PHP timezone identifier (e.g. UTC, Europe/Madrid) used when displaying dates. */
        private readonly string $timezone,
        /** 'NOTICE'|'PRIVMSG' — how to send replies to the user. */
        private readonly string $messageType,
        private readonly NickServCommandRegistry $registry,
        private readonly PendingVerificationRegistry $pendingVerificationRegistry,
        private readonly RecoveryTokenRegistry $recoveryTokenRegistry,
    ) {
    }

    /**
     * Translate a message key and send it to the command sender (NOTICE or PRIVMSG per user preference).
     *
     * Parameter keys are automatically wrapped with % so callers can write
     * ['nickname' => 'foo'] instead of ['%nickname%' => 'foo'].
     *
     * @param array<string, mixed> $params Named placeholder parameters
     */
    public function reply(string $key, array $params = []): void
    {
        $message = $this->translator->trans($key, $this->wrapParams($params), 'nickserv', $this->language);
        $this->sendRaw($message);
    }

    /**
     * Send a pre-translated or literal string directly.
     * Use only when the string has already been localised.
     */
    public function replyRaw(string $message): void
    {
        $this->sendRaw($message);
    }

    public function getNotifier(): NickServNotifierInterface
    {
        return $this->notifier;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    /**
     * Formats a date/time in the user's timezone for display.
     * Use this for any date or time shown to the user so SET TIMEZONE applies everywhere.
     */
    public function formatDate(?DateTimeInterface $date): string
    {
        if (null === $date) {
            return '—';
        }

        $dt = $date instanceof DateTimeImmutable ? $date : DateTimeImmutable::createFromInterface($date);

        return $dt->setTimezone(new DateTimeZone($this->timezone))->format('d/m/Y H:i T');
    }

    public function getRegistry(): NickServCommandRegistry
    {
        return $this->registry;
    }

    public function getPendingVerificationRegistry(): PendingVerificationRegistry
    {
        return $this->pendingVerificationRegistry;
    }

    public function getRecoveryTokenRegistry(): RecoveryTokenRegistry
    {
        return $this->recoveryTokenRegistry;
    }

    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $this->wrapParams($params), 'nickserv', $this->language);
    }

    /**
     * Translate using an explicit language instead of the sender's language.
     * Useful when the message targets another user (e.g. KILL reason for a ghost).
     */
    public function transIn(string $key, array $params = [], string $language = ''): string
    {
        $lang = '' !== $language ? $language : $this->language;

        return $this->translator->trans($key, $this->wrapParams($params), 'nickserv', $lang);
    }

    /**
     * Ensures each parameter key is wrapped with % for Symfony's strtr-based translator.
     * Idempotent: keys already wrapped are left unchanged.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function wrapParams(array $params): array
    {
        $wrapped = [];
        foreach ($params as $key => $value) {
            $wrapped['%' . trim((string) $key, '%') . '%'] = $value;
        }

        return $wrapped;
    }

    private function sendRaw(string $message): void
    {
        if (null === $this->sender) {
            return;
        }

        $this->notifier->sendMessage($this->sender->uid, $message, $this->messageType);
    }
}
