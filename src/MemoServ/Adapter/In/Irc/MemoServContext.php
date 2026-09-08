<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\MemoServ\Application\Model\MemoAccountView;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\Security\IrcopAuthorizationSubject;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

use function trim;

final readonly class MemoServContext implements IrcopAuthorizationSubject
{
    /**
     * @param string[]           $args
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function __construct(
        public ?SenderView $sender,
        public ?MemoAccountView $senderAccount,
        public string $command,
        public array $args,
        private MemoServNotifierInterface $notifier,
        private TranslationInterface $translator,
        private string $language,
        private string $timezone,
        private string $messageType,
        private MemoServCommandRegistry $registry,
        private ServiceNicknameRegistry $serviceNicks,
    ) {}

    public function getSender(): ?SenderView
    {
        return $this->sender;
    }

    public function getSenderAccount(): ?MemoAccountView
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

    /**
     * @param array<string, mixed> $params
     */
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
