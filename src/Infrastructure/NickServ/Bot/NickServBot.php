<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Bot;

use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\Port\SendNoticePort;
use App\Application\Port\VhostCommandBuilderInterface;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\LocalUserModeSyncInterface;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function sprintf;

/**
 * NickServ pseudo-client: introduces on burst, implements NickServNotifierInterface.
 */
readonly class NickServBot implements NickServNotifierInterface, SendNoticePort, EventSubscriberInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly PendingNickRestoreRegistryInterface $pendingRegistry,
        private readonly LocalUserModeSyncInterface $localUserModeSync,
        private readonly VhostCommandBuilderInterface $vhostCommandBuilder,
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
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 100],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->introduce($event->connection, $event->serverSid);
    }

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
        $this->sendMessage($targetUidOrNick, $message, 'NOTICE');
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        if (!$this->connectionHolder->isConnected()) {
            $this->logger->warning('NickServBot: cannot send message — no active connection.', [
                'target' => $targetUidOrNick,
            ]);

            return;
        }

        $command = 'PRIVMSG' === $messageType ? 'PRIVMSG' : 'NOTICE';
        foreach (explode("\n", $message) as $line) {
            if ('' === $line) {
                continue;
            }
            $this->writeToConnection(sprintf(':%s %s %s :%s', $this->nickservUid, $command, $targetUidOrNick, $line));
        }
    }

    public function setUserAccount(string $targetUid, string $accountName): void
    {
        $logout = ('0' === $accountName);
        $modeDelta = $logout ? '-r' : '+r';
        $this->write(sprintf(':%s SVS2MODE %s %s', $this->getServerSid(), $targetUid, $modeDelta));

        $this->localUserModeSync->apply(new Uid($targetUid), $modeDelta);
    }

    public function setUserMode(string $targetUid, string $modes): void
    {
        $this->write(sprintf(':%s SVSMODE %s %s', $this->getServerSid(), $targetUid, $modes));
    }

    public function forceNick(string $targetUid, string $newNick): void
    {
        $this->pendingRegistry->mark($targetUid);
        $this->write(sprintf(':%s SVSNICK %s %s %d', $this->getServerSid(), $targetUid, $newNick, time()));
    }

    public function killUser(string $targetUid, string $reason): void
    {
        $this->write(sprintf(':%s KILL %s :%s', $this->getServerSid(), $targetUid, $reason));
    }

    public function setUserVhost(string $targetUid, string $vhost, string $sourceServerSid): void
    {
        $sid = $this->getServerSid();
        $line = '' !== $vhost
            ? $this->vhostCommandBuilder->getSetVhostLine($sid, $targetUid, $vhost)
            : $this->vhostCommandBuilder->getClearVhostLine($sid, $targetUid);
        $this->write($line);
    }

    private function getServerSid(): string
    {
        return $this->connectionHolder->getServerSid() ?? '';
    }

    /**
     * Sends a line to the connection and logs it. Use for protocol commands (SVSNICK, KILL, etc.).
     */
    private function write(string $line): void
    {
        if (!$this->writeToConnection($line)) {
            $this->logger->warning('NickServBot: cannot write — no active connection.', ['line' => $line]);

            return;
        }

        $this->logger->debug('> ' . $line);
    }

    /**
     * Sends a line to the connection without logging. Use for high-volume or user-facing output (e.g. NOTICEs).
     */
    private function writeToConnection(string $line): bool
    {
        if (!$this->connectionHolder->isConnected()) {
            return false;
        }

        $this->connectionHolder->writeLine($line);

        return true;
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
