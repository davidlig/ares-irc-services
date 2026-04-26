<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\OperServ\Command\OperServNotifierInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SendNoticePort;
use App\Application\Port\UserMessageTypeResolverInterface;
use App\Application\Services\Antiflood\AntifloodRegistry;
use App\Application\Services\Antiflood\ClientKeyResolver;
use App\Domain\IRC\Event\MessageReceivedEvent;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;

/**
 * Intercepts PRIVMSG/SQUERY directed at service bots before they reach ServiceCommandGateway.
 *
 * When a user exceeds the allowed number of commands within a time window, they are
 * locked out for a cooldown period. The first time they are blocked during a lockout,
 * they receive a single NOTICE informing them of the rate limit. Subsequent commands
 * during the same lockout are silently dropped.
 *
 * IRCops (isOper) are exempt from flood blocking — their commands always pass through.
 *
 * Priority 10 ensures this runs before ServiceCommandGateway (priority 0).
 */
final readonly class AntifloodSubscriber implements EventSubscriberInterface
{
    private const string COLOR_RED = "\x0304";

    private const string COLOR_BLUE = "\x0302";

    private const string COLOR_RESET = "\x03";

    public function __construct(
        private AntifloodRegistry $registry,
        private ClientKeyResolver $clientKeyResolver,
        private ServiceCommandGateway $gateway,
        private NetworkUserLookupPort $userLookup,
        private SendNoticePort $sendNotice,
        private UserMessageTypeResolverInterface $messageTypeResolver,
        private OperServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private string $defaultLanguage,
        private ?string $debugChannel,
        private int $maxMessages,
        private int $windowSeconds,
        private int $cooldownSeconds,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageReceivedEvent::class => ['onMessage', 10],
        ];
    }

    public function onMessage(MessageReceivedEvent $event): void
    {
        if ($this->maxMessages <= 0) {
            return;
        }

        $message = $event->message;

        if (!in_array($message->command, ['PRIVMSG', 'SQUERY'], true)) {
            return;
        }

        $target = $message->params[0] ?? '';
        $sourceId = $message->prefix ?? '';

        if ('' === $target || '' === $sourceId) {
            return;
        }

        $listener = $this->gateway->findListenerFor($target);

        if (null === $listener) {
            return;
        }

        $sender = $this->userLookup->findByUid($sourceId);

        if (null === $sender) {
            return;
        }

        if ($sender->isOper) {
            return;
        }

        $clientKey = $this->clientKeyResolver->getClientKey($sender);

        $remaining = $this->registry->getRemainingLockoutSeconds(
            $clientKey,
            $this->maxMessages,
            $this->windowSeconds,
            $this->cooldownSeconds,
        );

        if ($remaining > 0) {
            if (!$this->registry->isNotified($clientKey)) {
                $serviceUid = $listener->getServiceUid() ?? $listener->getServiceName();
                $messageType = $this->messageTypeResolver->resolveByNick($sender->nick);
                $notice = $this->translator->trans('antiflood.blocked', ['%seconds%' => (string) $remaining], 'common');
                $this->sendNotice->sendMessage($serviceUid, $sender->uid, $notice, $messageType);
                $this->registry->markNotified($clientKey);
                $this->logToDebugChannel($sender->nick, $clientKey, $remaining);
            }

            $this->logger->info('Antiflood: blocked {nick} ({uid}), {seconds}s remaining', [
                'nick' => $sender->nick,
                'uid' => $sender->uid,
                'seconds' => $remaining,
            ]);

            $event->stopPropagation();

            return;
        }

        $this->registry->recordCommand($clientKey, $this->windowSeconds);
    }

    private function logToDebugChannel(string $nick, string $clientKey, int $remaining): void
    {
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

        $coloredNick = self::COLOR_BLUE . $nick . self::COLOR_RESET;
        $coloredCommand = self::COLOR_RED . 'ANTIFLOOD' . self::COLOR_RESET;
        $coloredKey = self::COLOR_BLUE . $clientKey . self::COLOR_RESET;

        $message = $this->translator->trans(
            'antiflood.debug_channel',
            [
                '%nick%' => $coloredNick,
                '%command%' => $coloredCommand,
                '%key%' => $coloredKey,
                '%seconds%' => (string) $remaining,
            ],
            'common',
            $this->defaultLanguage,
        );

        $this->notifier->sendMessage($this->debugChannel, $message, 'NOTICE');
    }
}
