<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command;

use App\Application\OperServ\IrcopAccessHelper;
use App\Application\Port\SenderView;
use App\Application\Port\TranslationInterface;
use App\Application\Security\IrcopContextInterface;
use App\Application\Shared\ServiceNicknameRegistry;
use App\Domain\NickServ\Entity\RegisteredNick;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

final readonly class OperServContext implements IrcopContextInterface
{
    /**
     * @param string[]           $args
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function __construct(
        public ?SenderView $sender,
        public ?RegisteredNick $senderAccount,
        public string $command,
        public array $args,
        private OperServNotifierInterface $notifier,
        private TranslationInterface $translator,
        private string $language,
        private string $timezone,
        private string $messageType,
        private OperServCommandRegistry $registry,
        private IrcopAccessHelper $accessHelper,
        private ServiceNicknameRegistry $serviceNicks,
    ) {}

    public function getSender(): ?SenderView
    {
        return $this->sender;
    }

    public function getSenderAccount(): ?RegisteredNick
    {
        return $this->senderAccount;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function reply(string $key, array $params = []): void
    {
        $message = $this->translator->trans($key, $this->wrapParams($params), 'operserv', $this->language);
        $this->sendRaw($message);
    }

    public function replyRaw(string $message): void
    {
        $this->sendRaw($message);
    }

    public function getNotifier(): OperServNotifierInterface
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

    public function getRegistry(): OperServCommandRegistry
    {
        return $this->registry;
    }

    public function getAccessHelper(): IrcopAccessHelper
    {
        return $this->accessHelper;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $this->wrapParams($params), 'operserv', $this->language);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function transForDomain(string $key, string $domain, array $params = []): string
    {
        return $this->translator->trans($key, $this->wrapParams($params), $domain, $this->language);
    }

    public function isRoot(): bool
    {
        if (null === $this->sender) {
            return false;
        }

        if (!$this->sender->isIdentified) {
            return false;
        }

        return $this->accessHelper->isRoot($this->sender->nick);
    }

    public function getBotName(): string
    {
        return $this->notifier->getNick();
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
