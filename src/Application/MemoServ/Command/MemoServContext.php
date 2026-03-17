<?php

declare(strict_types=1);

namespace App\Application\MemoServ\Command;

use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Context for MemoServ command execution. Commands reply via reply() / replyRaw().
 */
readonly class MemoServContext
{
    public function __construct(
        public readonly ?SenderView $sender,
        public readonly ?RegisteredNick $senderAccount,
        public readonly string $command,
        /** @var string[] */
        public readonly array $args,
        private readonly MemoServNotifierInterface $notifier,
        private readonly TranslatorInterface $translator,
        private readonly string $language,
        private readonly string $timezone,
        private readonly string $messageType,
        private readonly MemoServCommandRegistry $registry,
    ) {
    }

    public function reply(string $key, array $params = []): void
    {
        $message = $this->translator->trans($key, $this->wrapParams($params), 'memoserv', $this->language);
        $this->sendRaw($message);
    }

    public function replyRaw(string $message): void
    {
        $this->sendRaw($message);
    }

    public function getNotifier(): MemoServNotifierInterface
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

    public function formatDate(?DateTimeInterface $date): string
    {
        if (null === $date) {
            return '—';
        }

        $dt = $date instanceof DateTimeImmutable ? $date : DateTimeImmutable::createFromInterface($date);

        return $dt->setTimezone(new DateTimeZone($this->timezone))->format('d/m/Y H:i T');
    }

    public function getRegistry(): MemoServCommandRegistry
    {
        return $this->registry;
    }

    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $this->wrapParams($params), 'memoserv', $this->language);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function wrapParams(array $params): array
    {
        $wrapped = ['%bot%' => $this->notifier->getNick()];
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
