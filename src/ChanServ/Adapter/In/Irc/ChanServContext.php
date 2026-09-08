<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

use App\ChanServ\Application\Model\ChanAccountView;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Shared\Application\Port\ChannelModeSupportInterface;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\Security\IrcopAuthorizationSubject;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final readonly class ChanServContext implements IrcopAuthorizationSubject
{
    public function __construct(
        public ?SenderView $sender,
        public ?ChanAccountView $senderAccount,
        public string $command,
        /** @var string[] */
        public array $args,
        private ChanServNotifierInterface $notifier,
        private TranslationInterface $translator,
        private string $language,
        private string $timezone,
        /** @var 'NOTICE'|'PRIVMSG' */
        private string $messageType,
        private ChanServCommandRegistry $registry,
        private ChannelLookupPort $channelLookup,
        private ChannelModeSupportInterface $channelModeSupport,
        private NetworkUserLookupPort $userLookup,
        private ServiceNicknameRegistry $serviceNicks,
        public bool $isLevelFounder = false,
    ) {}

    public function getSender(): ?SenderView
    {
        return $this->sender;
    }

    public function getSenderAccount(): ?ChanAccountView
    {
        return $this->senderAccount;
    }

    public function getSenderAccountId(): ?int
    {
        return $this->senderAccount?->id;
    }

    public function getSenderNickname(): ?string
    {
        return $this->sender?->nick;
    }

    public function isSenderIdentified(): bool
    {
        return null !== $this->sender && $this->sender->isIdentified;
    }

    public function isSenderIrcOperator(): bool
    {
        return null !== $this->sender && $this->sender->isOper;
    }

    /**
     * @param array<string, mixed> $params
     */
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

    /**
     * @param array<string, mixed> $params
     */
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

    public function getUserLookup(): NetworkUserLookupPort
    {
        return $this->userLookup;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function wrapParams(array $params): array
    {
        $wrapped = $this->serviceNicks->getAllPlaceholders($this->notifier->getNick());
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
