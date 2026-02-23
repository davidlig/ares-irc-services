<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Bot;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use App\Infrastructure\NickServ\PendingNickRestoreRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

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
readonly class NickServBot implements NickServNotifierInterface, EventSubscriberInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly PendingNickRestoreRegistry $pendingRegistry,
        private readonly string $serverSid,
        private readonly string $servicesHostname,
        private readonly string $nickservUid,
        private readonly string $nickservNick = 'NickServ',
        private readonly string $nickservIdent = 'NickServ',
        private readonly string $nickservRealname = 'Nickname Registration Services',
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
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 100],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->introduce($event->connection, $event->serverSid);
    }

    /**
     * Sends the UID line to introduce NickServ to the network.
     * Must be called before our EOS so the IRCd registers the pseudo-client.
     */
    private function introduce(ConnectionInterface $connection, string $serverSid): void
    {
        $ts = time();
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

        $connection->writeLine($uid);

        $this->logger->info('NickServ introduced to network.', [
            'uid' => $this->nickservUid,
            'nick' => $this->nickservNick,
            'host' => $this->servicesHostname,
        ]);
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        foreach (explode("\n", $message) as $line) {
            if ('' === $line) {
                continue;
            }
            $this->write(sprintf(':%s NOTICE %s :%s', $this->nickservUid, $targetUidOrNick, $line));
        }
    }

    /**
     * Authenticate a user by setting the +r (registered nick) user mode (UnrealIRCd 6+).
     *
     * SVS2MODE format (from src/modules/svsmode.c):
     *   :<server> SVS2MODE <target_uid_or_nick> <modes>
     *   parv[1] = target (UID or nick), parv[2] = mode string — NO timestamp.
     *
     * SVS2MODE (show_change=1) is used instead of SVSMODE so that the IRCd
     * sends a ":server MODE nick :+r" notification to the user's IRC client.
     *
     * Note: this does NOT set the services account name shown in /WHOIS as
     * "is logged in as <account>". If that is needed, add a SVSLOGIN call:
     *   :<server> SVSLOGIN * <target_uid> <account_name>
     *
     * Pass '0' as $accountName to log a user out (-r removes +r).
     *
     * Source MUST be the services server SID, not a pseudo-client UID.
     */
    public function setUserAccount(string $targetUid, string $accountName): void
    {
        $logout = ('0' === $accountName);

        $this->write(sprintf(':%s SVS2MODE %s %s', $this->serverSid, $targetUid, $logout ? '-r' : '+r'));
    }

    /**
     * Set raw user modes via SVSMODE (silent — user's client is NOT notified).
     * Do NOT include a timestamp; parv format is: <target> <modes>.
     */
    public function setUserMode(string $targetUid, string $modes): void
    {
        $this->write(sprintf(':%s SVSMODE %s %s', $this->serverSid, $targetUid, $modes));
    }

    public function forceNick(string $targetUid, string $newNick): void
    {
        $this->pendingRegistry->mark($targetUid);
        $this->write(sprintf(':%s SVSNICK %s %s %d', $this->serverSid, $targetUid, $newNick, time()));
    }

    public function killUser(string $targetUid, string $reason): void
    {
        $this->write(sprintf(':%s KILL %s :%s', $this->serverSid, $targetUid, $reason));
    }

    /**
     * Set or clear a user's virtual host via SVSHOST (UnrealIRCd).
     * Set: :<serverSid> SVSHOST <targetUid> :<vhost>
     * Clear: :<serverSid> SVSHOST <targetUid> :*
     * (UnrealIRCd accepts :* to remove vhost; see server protocol / HELPOP SVSCMDS).
     */
    public function setUserVhost(string $targetUid, string $vhost): void
    {
        $hostParam = '' !== $vhost ? $vhost : '*';
        $this->write(sprintf(':%s SVSHOST %s :%s', $this->serverSid, $targetUid, $hostParam));
    }

    private function write(string $line): void
    {
        if (!$this->connectionHolder->isConnected()) {
            $this->logger->warning('NickServBot: cannot write — no active connection.', ['line' => $line]);

            return;
        }

        $this->connectionHolder->writeLine($line);
        $this->logger->debug('> ' . $line);
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
