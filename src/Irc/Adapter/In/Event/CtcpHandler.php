<?php

declare(strict_types=1);

namespace App\Irc\Adapter\In\Event;

use App\Irc\Adapter\Event\MessageReceivedEvent;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SendCtcpPort;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use App\Irc\Application\Port\Out\ServiceUserPreferences;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function in_array;

final readonly class CtcpHandler implements EventSubscriberInterface
{
    private const string CTCP_PATTERN = '/^\x01([^\x01]+)\x01$/';

    public function __construct(
        private SendCtcpPort $sendCtcp,
        private SendNoticePort $sendNotice,
        private CtcpVersionResponder $versionResponder,
        private NetworkUserLookupPort $userLookup,
        private ServiceUserPreferences $languageResolver,
        private ServiceUidRegistry $uidRegistry,
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
        $message = $event->message;

        if (!in_array($message->command, ['PRIVMSG', 'SQUERY'], true)) {
            return;
        }

        $text = $message->trailing ?? '';
        $sender = $message->prefix ?? '';
        $target = $message->params[0] ?? '';

        if ('' === $text || '' === $sender || '' === $target || !preg_match(self::CTCP_PATTERN, $text, $matches)) {
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
            ? $this->languageResolver->languageFor($sender->uid, $sender->nick)
            : $this->languageResolver->defaultLanguage();

        $targetLower = strtolower($target);
        $serviceUid = $this->uidRegistry->getUid($targetLower) ?? $this->uidRegistry->getUidByNickname($target) ?? $this->uidRegistry->getUidByUid($target);
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
