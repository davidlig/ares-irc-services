<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\ServiceBridge;

use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SendCtcpPort;
use App\Application\Port\SendNoticePort;
use App\Domain\IRC\Event\MessageReceivedEvent;
use App\Infrastructure\NickServ\UserLanguageResolver;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class CtcpHandler implements EventSubscriberInterface
{
    private const string CTCP_PATTERN = '/^\x01([^\x01]+)\x01$/';

    /** @var array<string, string> Map of lowercase service name to UID */
    private readonly array $serviceNameToUid;

    /** @var array<string, string> Map of UID to UID (for reverse lookup by UID) */
    private readonly array $uidToUid;

    /**
     * @param array<string, string> $serviceUidMap Map of lowercase service name to UID (e.g., ['nickserv' => '002AAAAAA'])
     */
    public function __construct(
        private SendCtcpPort $sendCtcp,
        private SendNoticePort $sendNotice,
        private CtcpVersionResponder $versionResponder,
        private NetworkUserLookupPort $userLookup,
        private UserLanguageResolver $languageResolver,
        array $serviceUidMap,
        private LoggerInterface $logger = new NullLogger(),
    ) {
        $this->serviceNameToUid = $serviceUidMap;
        $this->uidToUid = array_combine(array_values($serviceUidMap), array_values($serviceUidMap));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MessageReceivedEvent::class => ['onMessage', 10],
        ];
    }

    public function onMessage(MessageReceivedEvent $event): void
    {
        $message = $event->message;

        if ('PRIVMSG' !== $message->command) {
            return;
        }

        $text = $message->trailing ?? '';
        $sender = $message->prefix ?? '';
        $target = $message->params[0] ?? '';

        if ('' === $text || '' === $sender || '' === $target) {
            return;
        }

        if (!preg_match(self::CTCP_PATTERN, $text, $matches)) {
            return;
        }

        $ctcpCommand = strtoupper($matches[1]);

        $this->logger->debug('CTCP received: {command} from {sender} to {target}', [
            'command' => $ctcpCommand,
            'sender' => $sender,
            'target' => $target,
        ]);

        if ('VERSION' !== $ctcpCommand) {
            return;
        }

        if ($this->handleVersion($sender, $target)) {
            $event->stopPropagation();
        }
    }

    private function handleVersion(string $senderUid, string $target): bool
    {
        $sender = $this->userLookup->findByUid($senderUid);
        $language = null !== $sender
            ? $this->languageResolver->resolve($sender)
            : $this->languageResolver->getDefault();

        $targetLower = strtolower($target);
        $serviceUid = $this->serviceNameToUid[$targetLower] ?? $this->uidToUid[$target] ?? null;
        if (null === $serviceUid) {
            $this->logger->warning('CTCP VERSION: unknown target service', ['target' => $target]);

            return false;
        }

        $this->sendCtcp->sendCtcpReply(
            $serviceUid,
            $senderUid,
            'VERSION',
            $this->versionResponder->getVersionResponse(),
        );

        foreach ($this->versionResponder->getAsciiArtLines($language) as $line) {
            $this->sendNotice->sendMessage($serviceUid, $senderUid, $line, 'NOTICE');
        }

        return true;
    }
}
