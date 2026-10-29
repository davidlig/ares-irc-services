<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Adapter\Event\MessageReceivedEvent;
use App\Irc\Application\Antiflood\AntifloodRegistry;
use App\Irc\Application\Antiflood\ClientKeyResolver;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceDebugNotifierInterface;
use App\Irc\Application\Port\Out\ServiceUserPreferences;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\Role;
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
 * IRCops (isOper) and identified root admins are exempt from flood blocking.
 *
 * Priority 10 ensures this runs before ServiceCommandGateway (priority 0).
 */
final readonly class AntifloodSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AntifloodRegistry $registry,
        private ClientKeyResolver $clientKeyResolver,
        private ServiceCommandGateway $gateway,
        private NetworkUserLookupPort $userLookup,
        private SendNoticePort $sendNotice,
        private ServiceUserPreferences $messageTypeResolver,
        private ServiceDebugNotifierInterface $debugNotifier,
        private OperatorAuthorizationQuery $authorization,
        private NickAccountQuery $nickAccounts,
        private TranslatorInterface $translator,
        private string $defaultLanguage,
        private ?string $debugChannel,
        private int $maxMessages,
        private int $windowSeconds,
        private int $cooldownSeconds,
        private IrcRenderer $renderer,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            MessageReceivedEvent::class => ['onMessage', 10],
        ];
    }

    public function onMessage(MessageReceivedEvent $event): void
    {
        $prefix = $event->message->prefix;
        if (null === $prefix || '' === $prefix || !$this->shouldProcessMessage($event)) {
            return;
        }

        $this->processFloodCheck($event, $prefix);
    }

    private function shouldProcessMessage(MessageReceivedEvent $event): bool
    {
        if ($this->maxMessages <= 0) {
            return false;
        }

        $message = $event->message;

        return in_array($message->command, ['PRIVMSG', 'SQUERY'], true)
            && '' !== ($message->params[0] ?? '')
            && null !== $this->gateway->findListenerFor($message->params[0]);
    }

    private function processFloodCheck(MessageReceivedEvent $event, string $prefix): void
    {
        $message = $event->message;

        $target = $message->params[0];
        $sender = $this->userLookup->findByUid($prefix);
        $listener = $this->gateway->findListenerFor($target);

        if (null === $sender || null === $listener || $sender->isOper || $this->isRoot($sender)) {
            return;
        }

        $clientKey = $this->clientKeyResolver->getClientKey($sender);

        $remaining = $this->registry->getRemainingLockoutSeconds(
            $clientKey,
            $this->maxMessages,
            $this->windowSeconds,
            $this->cooldownSeconds,
            $event->occurredAt->getTimestamp(),
        );

        if ($remaining > 0) {
            if (!$this->registry->isNotified($clientKey)) {
                $serviceUid = $listener->getServiceUid() ?? $listener->getServiceName();
                $messageType = $this->messageTypeResolver->prefersPrivateMessages($sender->nick) ? 'PRIVMSG' : 'NOTICE';
                $notice = $this->renderer->reply(Role::Warning, $this->translator->trans('antiflood.blocked', [], 'common'), ['%seconds%' => (string) $remaining]);
                $this->sendNotice->sendMessage($serviceUid, $sender->uid, $notice, $messageType);
                $this->registry->markNotified($clientKey);
                $this->logToDebugChannel($sender, $remaining);
            }

            $this->logger->info('Antiflood: blocked {nick} ({uid}), {seconds}s remaining', [
                'nick' => $sender->nick,
                'uid' => $sender->uid,
                'seconds' => $remaining,
            ]);

            $event->stopPropagation();

            return;
        }

        $this->registry->recordCommand($clientKey, $this->windowSeconds, $event->occurredAt->getTimestamp());
    }

    private function isRoot(SenderView $sender): bool
    {
        $accountId = $sender->isIdentified ? $this->nickAccounts->findIdByNick($sender->nick) : null;

        return $this->authorization->root(new OperatorActor(
            $sender->nick,
            $accountId,
            $sender->isIdentified,
            $sender->isOper,
        ))->granted;
    }

    private function logToDebugChannel(SenderView $sender, int $remaining): void
    {
        if (null === $this->debugChannel || '' === $this->debugChannel) {
            return;
        }

        $coloredNick = new Fragment($sender->nick, Component::Actor);
        $coloredCommand = new Fragment('ANTIFLOOD', Component::ModeChange);
        $coloredHost = new Fragment($this->clientKeyResolver->getClientDescription($sender), Component::Actor);

        $message = $this->renderer->component(Component::Text, $this->translator->trans(
            'antiflood.debug_channel',
            [],
            'common',
            $this->defaultLanguage,
        ), [
            '%nick%' => $coloredNick,
            '%command%' => $coloredCommand,
            '%host%' => $coloredHost,
            '%seconds%' => (string) $remaining,
        ]);

        $this->debugNotifier->notify($message);
    }
}
