<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Bot;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceUidProviderInterface;
use App\Application\NickServ\Command\NickServNotifierInterface;
use App\Application\NickServ\PendingNickRestoreRegistryInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SendNoticePort;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\IRC\LocalUserModeSyncInterface;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * NickServ pseudo-client: introduces on burst, implements NickServNotifierInterface.
 * Sending NOTICE/PRIVMSG is delegated to SendNoticePort (implemented by Core).
 */
final readonly class NickServBot implements NickServNotifierInterface, ServiceNicknameProviderInterface, ServiceUidProviderInterface, EventSubscriberInterface
{
    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly SendNoticePort $sendNoticePort,
        private readonly PendingNickRestoreRegistryInterface $pendingRegistry,
        private readonly LocalUserModeSyncInterface $localUserModeSync,
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
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }

        $line = $module->getIntroductionFormatter()->formatIntroduction(
            $serverSid,
            $this->nickservNick,
            $this->nickservIdent,
            $this->servicesHostname,
            $this->nickservUid,
            $this->nickservRealname,
        );

        $connection->writeLine($line);

        $this->logger->info('NickServ introduced to network.', [
            'uid' => $this->nickservUid,
            'nick' => $this->nickservNick,
            'host' => $this->servicesHostname,
        ]);
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->sendNoticePort->sendNotice($this->getUid(), $targetUidOrNick, $message);
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        $this->sendNoticePort->sendMessage($this->getUid(), $targetUidOrNick, $message, $messageType);
    }

    public function setUserAccount(string $targetUid, string $accountName): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }
        $modeDelta = ('0' === $accountName) ? '-r' : '+r';
        $module->getServiceActions()->setUserAccount($this->getServerSid(), $targetUid, $accountName);
        $this->localUserModeSync->apply(new Uid($targetUid), $modeDelta);
    }

    public function setUserMode(string $targetUid, string $modes): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }
        $module->getServiceActions()->setUserMode($this->getServerSid(), $targetUid, $modes);
    }

    public function forceNick(string $targetUid, string $newNick): void
    {
        $this->pendingRegistry->mark($targetUid);
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }
        $module->getServiceActions()->forceNick($this->getServerSid(), $targetUid, $newNick);
    }

    public function killUser(string $targetUid, string $reason): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }
        $module->getServiceActions()->killUser($this->getServerSid(), $targetUid, $reason);
    }

    public function setUserVhost(string $targetUid, string $vhost, string $sourceServerSid): void
    {
        $sender = $this->userLookup->findByUid($targetUid);
        if (null !== $sender) {
            if ('' !== $vhost) {
                if ($sender->displayHost === $vhost) {
                    return;
                }
            }
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }
        $sid = $this->getServerSid();
        $vhostBuilder = $module->getVhostCommandBuilder();
        $line = '' !== $vhost
            ? $vhostBuilder->getSetVhostLine($sid, $targetUid, $vhost)
            : $vhostBuilder->getClearVhostLine($sid, $targetUid);
        $this->write($line);

        // Update local NetworkUser state to keep displayHost in sync
        $this->userLookup->updateVhost($targetUid, $vhost);
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

    public function getServiceKey(): string
    {
        return 'nickserv';
    }

    public function getNickname(): string
    {
        return $this->nickservNick;
    }
}
