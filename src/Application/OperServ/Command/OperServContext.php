<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command;

use App\Application\ApplicationPort\ServiceNicknameRegistry;
use App\Application\OperServ\IrcopAccessHelper;
use App\Application\Port\SenderView;
use App\Application\Security\IrcopContextInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class OperServContext implements IrcopContextInterface
{
    public function __construct(
        public readonly ?SenderView $sender,
        public readonly ?RegisteredNick $senderAccount,
        public readonly string $command,
        /** @var string[] */
        public readonly array $args,
        private readonly OperServNotifierInterface $notifier,
        private readonly TranslatorInterface $translator,
        private readonly string $language,
        private readonly string $timezone,
        private readonly string $messageType,
        private readonly OperServCommandRegistry $registry,
        private readonly IrcopAccessHelper $accessHelper,
        private readonly ServiceNicknameRegistry $serviceNicks,
    ) {
    }

    public function getSender(): ?SenderView
    {
        return $this->sender;
    }

    public function getSenderAccount(): ?RegisteredNick
    {
        return $this->senderAccount;
    }

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

    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $this->wrapParams($params), 'operserv', $this->language);
    }

    public function transForDomain(string $key, string $domain, array $params = []): string
    {
        return $this->translator->trans($key, $this->wrapParams($params), $domain, $this->language);
    }

    public function isRoot(): bool
    {
        if (null === $this->sender) {
            return false;
        }

        return $this->accessHelper->isRoot($this->sender->nick);
    }

    public function getBotName(): string
    {
        return $this->notifier->getNick();
    }

    /** @return array<string, mixed> */
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
