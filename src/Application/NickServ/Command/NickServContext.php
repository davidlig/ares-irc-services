<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command;

use App\Domain\IRC\Network\NetworkUser;
use App\Domain\NickServ\Entity\RegisteredNick;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Carries all the context a NickServ command needs to execute.
 *
 * Commands send replies via reply() / replyLines() rather than directly
 * touching the connection, which keeps them decoupled from IRC transport.
 */
class NickServContext
{
    public function __construct(
        public readonly ?NetworkUser $sender,
        public readonly ?RegisteredNick $senderAccount,
        public readonly string $command,
        /** @var string[] */
        public readonly array $args,
        private readonly NickServNotifierInterface $notifier,
        private readonly TranslatorInterface $translator,
        private readonly string $language,
        private readonly NickServCommandRegistry $registry,
    ) {
    }

    /**
     * Translate a message key and send it as a NOTICE to the command sender.
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

    public function getRegistry(): NickServCommandRegistry
    {
        return $this->registry;
    }

    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $this->wrapParams($params), 'nickserv', $this->language);
    }

    /**
     * Ensures each parameter key is wrapped with % for Symfony's strtr-based translator.
     * Idempotent: keys already wrapped are left unchanged.
     *
     * @param array<string, mixed> $params
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
        if ($this->sender === null) {
            return;
        }

        $this->notifier->sendNotice($this->sender->uid->value, $message);
    }
}
