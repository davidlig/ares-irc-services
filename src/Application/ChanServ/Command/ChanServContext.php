<?php

declare(strict_types=1);

namespace App\Application\ChanServ\Command;

use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelModeSupportInterface;
use App\Application\Port\ChannelView;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Context for ChanServ command execution. Commands reply via reply() / replyRaw();
 * channel data and actions go through the injected ports and notifier.
 */
class ChanServContext
{
    public function __construct(
        public readonly ?SenderView $sender,
        public readonly ?RegisteredNick $senderAccount,
        public readonly string $command,
        /** @var string[] */
        public readonly array $args,
        private readonly ChanServNotifierInterface $notifier,
        private readonly TranslatorInterface $translator,
        private readonly string $language,
        private readonly string $timezone,
        private readonly string $messageType,
        private readonly ChanServCommandRegistry $registry,
        private readonly ChannelLookupPort $channelLookup,
        private readonly ChannelModeSupportInterface $channelModeSupport,
    ) {
    }

    public function reply(string $key, array $params = []): void
    {
        $message = $this->translator->trans($key, $this->wrapParams($params), 'chanserv', $this->language);
        $this->sendRaw($message);
    }

    public function replyRaw(string $message): void
    {
        $this->sendRaw($message);
    }

    public function getNotifier(): ChanServNotifierInterface
    {
        return $this->notifier;
    }

    public function getChannelLookup(): ChannelLookupPort
    {
        return $this->channelLookup;
    }

    public function getChannelModeSupport(): ChannelModeSupportInterface
    {
        return $this->channelModeSupport;
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

    public function getRegistry(): ChanServCommandRegistry
    {
        return $this->registry;
    }

    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $this->wrapParams($params), 'chanserv', $this->language);
    }

    /** First argument as channel name (e.g. #channel). Returns null if missing or not channel-like. */
    public function getChannelNameArg(int $index = 0): ?string
    {
        $name = $this->args[$index] ?? '';

        return str_starts_with($name, '#') ? $name : null;
    }

    /** Get current channel state on the network (modes, topic) or null if not on network. */
    public function getChannelView(string $channelName): ?ChannelView
    {
        return $this->channelLookup->findByChannelName($channelName);
    }

    /**
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
