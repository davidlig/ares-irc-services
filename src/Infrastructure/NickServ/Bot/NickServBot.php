<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Bot;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Represents NickServ as a pseudo-client (service bot) on the IRC network.
 *
 * On NetworkBurstCompleteEvent (high priority so it runs BEFORE EOS is sent):
 *   - Introduces itself to the network via UID
 *
 * Implements NickServNotifierInterface to send NOTICEs, set user modes
 * and force nick changes on behalf of NickServ.
 *
 * UnrealIRCd requirements:
 *   - The services server must be listed in ulines{} to use SVSMODE/SVSNICK.
 *   - Example:  ulines { "ares-services.davidlig.net"; };
 */
class NickServBot implements NickServNotifierInterface, EventSubscriberInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly string $serverSid,
        private readonly string $servicesHostname,
        private readonly string $nickservUid,
        private readonly string $nickservNick = 'NickServ',
        private readonly string $nickservIdent = 'NickServ',
        private readonly string $nickservRealname = 'Nickname Registration Services',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Priority 100: runs before the protocol handler sends our EOS (priority 0)
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 100],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->introduce($event->serverSid);
    }

    /**
     * Sends the UID line to introduce NickServ to the network.
     * Must be called before our EOS so the IRCd registers the pseudo-client.
     */
    private function introduce(string $serverSid): void
    {
        $ts  = time();
        $uid = sprintf(
            ':%s UID %s 1 %d %s %s %s 0 +Sio * * * :%s',
            $serverSid,
            $this->nickservNick,
            $ts,
            $this->nickservIdent,
            $this->servicesHostname,
            $this->nickservUid,
            $this->nickservRealname,
        );

        $this->connectionHolder->writeLine($uid);

        $this->logger->info('NickServ introduced to network.', [
            'uid'  => $this->nickservUid,
            'nick' => $this->nickservNick,
            'host' => $this->servicesHostname,
        ]);
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        // Split multi-line messages (translations may include \n)
        foreach (explode("\n", $message) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $this->connectionHolder->writeLine(
                sprintf(':%s NOTICE %s :%s', $this->nickservUid, $targetUidOrNick, $line)
            );
        }
    }

    public function setUserMode(string $targetUid, string $modes): void
    {
        // SVSMODE requires ulines in UnrealIRCd
        $this->connectionHolder->writeLine(
            sprintf(':%s SVSMODE %s %d %s', $this->nickservUid, $targetUid, time(), $modes)
        );
    }

    public function forceNick(string $targetUid, string $newNick): void
    {
        // SVSNICK requires ulines in UnrealIRCd
        $this->connectionHolder->writeLine(
            sprintf(':%s SVSNICK %s %s %d', $this->serverSid, $targetUid, $newNick, time())
        );
    }

    public function getNick(): string
    {
        return $this->nickservNick;
    }

    public function getUid(): string
    {
        return $this->nickservUid;
    }
}
