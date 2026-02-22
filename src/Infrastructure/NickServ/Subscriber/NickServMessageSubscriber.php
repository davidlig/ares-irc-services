<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Subscriber;

use App\Application\NickServ\NickServService;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Domain\IRC\Repository\NetworkUserRepositoryInterface;
use App\Domain\IRC\ValueObject\Uid;
use App\Domain\NickServ\Exception\InvalidCredentialsException;
use App\Domain\NickServ\Exception\NickAlreadyRegisteredException;
use App\Infrastructure\IRC\Security\SensitiveDataRedactor;
use App\Infrastructure\NickServ\Bot\NickServBot;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

use function sprintf;

/**
 * Listens for PRIVMSG messages directed to NickServ and routes them
 * to NickServService for command dispatch.
 *
 * Handles both nick-based targeting ("NickServ") and UID-based targeting.
 */
class NickServMessageSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly NickServService $nickServService,
        private readonly NickServBot $nickServBot,
        private readonly NetworkUserRepositoryInterface $userRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Priorities per Symfony 7.4 event_dispatcher: higher = runs earlier; range -256..256.
     *
     * @see https://symfony.com/doc/7.4/event_dispatcher.html
     */
    public static function getSubscribedEvents(): array
    {
        return [
            MessageReceivedEvent::class => ['onMessage', 0],
        ];
    }

    public function onMessage(MessageReceivedEvent $event): void
    {
        $message = $event->message;

        if ('PRIVMSG' !== $message->command) {
            return;
        }

        $target = $message->params[0] ?? '';
        $text = $message->trailing ?? '';
        $sourceId = $message->prefix ?? '';

        // Check if the PRIVMSG is addressed to NickServ
        if (!$this->isAddressedToNickServ($target)) {
            return;
        }

        if ('' === $text || '' === $sourceId) {
            return;
        }

        // Resolve sender
        $sender = null;
        try {
            $sender = $this->userRepository->findByUid(new Uid($sourceId));
        } catch (InvalidArgumentException) {
            // Source may be a nick string (legacy)
        }

        if (null === $sender) {
            $this->logger->warning('NickServ: could not resolve sender UID: ' . $sourceId);

            return;
        }

        $this->logger->debug(sprintf(
            'NickServ: command from %s [%s]: %s',
            $sender->getNick()->value,
            $sender->uid->value,
            SensitiveDataRedactor::redactNickServCommand($text),
        ));

        try {
            $this->nickServService->dispatch($text, $sender);
        } catch (NickAlreadyRegisteredException $e) {
            // Domain exception bubbled up from RegisterCommand
            $this->nickServBot->sendNotice($sender->uid->value, $e->getMessage());
        } catch (InvalidCredentialsException $e) {
            $this->nickServBot->sendNotice($sender->uid->value, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger->error('NickServ dispatch error: ' . $e->getMessage(), [
                'exception' => $e,
                'sender' => $sender->uid->value,
                'text' => SensitiveDataRedactor::redactNickServCommand($text),
            ]);
        }
    }

    private function isAddressedToNickServ(string $target): bool
    {
        return 0 === strcasecmp($target, $this->nickServBot->getNick())
            || $this->nickServBot->getUid() === $target;
    }
}
