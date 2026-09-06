<?php

declare(strict_types=1);

namespace App\Infrastructure\NickServ\Bot;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceUidGeneratorInterface;
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
final class NickServBot implements NickServNotifierInterface, ServiceNicknameProviderInterface, ServiceUidProviderInterface, EventSubscriberInterface
{
    private string $uid = '';

    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly NetworkUserLookupPort $userLookup,
        private readonly SendNoticePort $sendNoticePort,
        private readonly PendingNickRestoreRegistryInterface $pendingRegistry,
        private readonly LocalUserModeSyncInterface $localUserModeSync,
        private readonly ServiceUidGeneratorInterface $uidGenerator,
        private readonly string $servicesVhost,
        private readonly string $nickservNick = 'NickServ',
        private readonly string $nickservIdent = 'NickServ',
        private readonly string $nickservRealname = 'Nickname Registration Services',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 100],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->uid = $this->uidGenerator->generateUid('nickserv');
        $this->introduce($event->connection, $event->serverSid);
    }

    private function introduce(ConnectionInterface $connection, string $serverSid): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }

        $module->getServiceActions()->introduceService(
            $serverSid,
            $this->nickservNick,
            $this->nickservIdent,
            $this->servicesVhost,
            $this->uid,
            $this->nickservRealname,
            $this->getServiceKey(),
        );

        $this->logger->info('NickServ introduced to network.', [
            'uid' => $this->uid,
            'nick' => $this->nickservNick,
            'host' => $this->servicesVhost,
        ]);
    }

    public function sendNotice(string $targetUidOrNick, string $message): void
    {
        $this->sendNoticePort->sendNotice($this->uid, $targetUidOrNick, $message);
    }

    public function sendMessage(string $targetUidOrNick, string $message, string $messageType): void
    {
        $this->sendNoticePort->sendMessage($this->uid, $targetUidOrNick, $message, $messageType);
    }

    public function setUserAccount(string $targetUid, string $accountName): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }
        $module->getServiceActions()->setUserAccount($this->getServerSid(), $targetUid, $accountName);

        $delta = ('0' === $accountName) ? '-r' : '+r';
        $this->localUserModeSync->apply(new Uid($targetUid), $delta);
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
        if (null !== $sender && '' !== $vhost) {
            if ($sender->displayHost === $vhost) {
                return;
            }
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return;
        }
        $sid = $this->getServerSid();
        $cloakedHost = null !== $sender ? $sender->cloakedHost : '';
        $module->getServiceActions()->setUserVhost($sid, $targetUid, $vhost, $cloakedHost);

        $this->userLookup->updateVhost($targetUid, '' === $vhost ? '*' : $vhost);
    }

    private function getServerSid(): string
    {
        return $this->connectionHolder->getServerSid() ?? '';
    }

    public function getNick(): string
    {
        return $this->nickservNick;
    }

    public function getUid(): string
    {
        return $this->uid;
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
