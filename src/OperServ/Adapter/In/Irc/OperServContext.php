<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Application\Port\TranslationInterface;
use App\Shared\Application\ServiceNicknameRegistry;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

use function trim;

final readonly class OperServContext
{
    /** @var list<string> */
    public array $args;

    /**
     * @param list<string>       $args
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function __construct(
        public ?SenderView $sender,
        public ?NickAccountData $senderAccount,
        public string $command,
        array $args,
        private OperServNotifierInterface $notifier,
        private TranslationInterface $translator,
        private string $language,
        private string $timezone,
        private string $messageType,
        private OperServCommandRegistry $registry,
        private ServiceNicknameRegistry $serviceNicks,
        private OperatorAuthorizationQuery $authorization,
    ) {
        $this->args = $args;
    }

    public function senderAccountId(): ?int
    {
        return $this->senderAccount?->id;
    }

    /** @param array<string, mixed> $params */
    public function reply(string $key, array $params = []): void
    {
        $this->sendRaw($this->trans($key, $params));
    }

    public function replyRaw(string $message): void
    {
        $this->sendRaw($message);
    }

    /** @param array<string, mixed> $params */
    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $this->wrapParams($params), 'operserv', $this->language);
    }

    public function formatDate(?DateTimeInterface $date): string
    {
        if (null === $date) {
            return '—';
        }

        $immutable = $date instanceof DateTimeImmutable ? $date : DateTimeImmutable::createFromInterface($date);

        return $immutable->setTimezone(new DateTimeZone($this->timezone))->format('d/m/Y H:i T');
    }

    public function commandRegistry(): OperServCommandRegistry
    {
        return $this->registry;
    }

    public function getBotName(): string
    {
        return $this->notifier->getNick();
    }

    public function isAuthorized(?string $permission): bool
    {
        if (null === $this->sender || null === $this->senderAccount) {
            return false;
        }

        $actor = new OperatorActor(
            nickname: $this->sender->nick,
            identifiedAccountId: $this->senderAccount->id,
            identified: $this->sender->isIdentified,
            ircOperator: $this->sender->isOper,
        );

        return match ($permission) {
            null => $this->authorization->ircOperator($actor)->granted,
            OperatorAuthorizationAttribute::IDENTIFIED => $this->authorization->identifiedAccount($actor)->granted,
            OperatorAuthorizationAttribute::ROOT => $this->authorization->root($actor)->granted,
            default => $this->authorization->permission($actor, $permission)->granted,
        };
    }

    /** @param array<string, mixed> $params
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
        if (null !== $this->sender) {
            $this->notifier->sendMessage($this->sender->uid, $message, $this->messageType);
        }
    }
}
