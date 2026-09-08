<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\OperServ\Application\Port\Out\GlobalMessageTransport;
use App\OperServ\Application\Service\PseudoClientUidGenerator;
use App\OperServ\Application\UseCase\Global\GlobalMessageType;
use App\OperServ\Domain\ValueObject\GlobalMessageMask;
use App\Shared\Application\Port\ActiveConnectionHolderInterface;
use App\Shared\Application\Port\SendNoticePort;
use App\Shared\Application\ServiceUidRegistry;

use function sprintf;

/** Bridges GLOBAL's semantic broadcast lifecycle to the active IRC protocol adapter. */
final readonly class ActiveConnectionGlobalMessageTransport implements GlobalMessageTransport
{
    private const int RESERVATION_DURATION_SECONDS = 86400;

    public function __construct(
        private ServiceUidRegistry $serviceUids,
        private PseudoClientUidGenerator $pseudoClientUids,
        private ActiveConnectionHolderInterface $connection,
        private NetworkUserLookupPort $users,
        private SendNoticePort $messages,
    ) {}

    public function serviceUidForNickname(string $nickname): ?string
    {
        return $this->serviceUids->getUidByNickname($nickname);
    }

    public function broadcastFromService(string $senderUid, string $message, GlobalMessageType $messageType): ?int
    {
        if (!$this->connection->isConnected()) {
            return null;
        }

        return $this->broadcast($senderUid, $message, $messageType);
    }

    public function broadcastFromTemporaryClient(GlobalMessageMask $sender, string $message, GlobalMessageType $messageType, string $actorNickname): ?int
    {
        $module = $this->connection->getProtocolModule();
        $serverSid = $this->connection->getServerSid();
        $uid = $this->pseudoClientUids->generate();
        if (null === $module || null === $serverSid || null === $uid) {
            return null;
        }

        $module->getNickReservation()?->reserveNickWithDuration(
            $sender->nickname,
            self::RESERVATION_DURATION_SECONDS,
            sprintf('Global message pseudo-client (sender: %s)', $actorNickname),
        );
        $module->getServiceActions()->introducePseudoClient(
            $serverSid,
            $sender->nickname,
            $sender->ident,
            $sender->vhost,
            $uid,
            $sender->nickname,
        );

        try {
            return $this->broadcast($uid, $message, $messageType);
        } finally {
            $module->getServiceActions()->quitPseudoClient($serverSid, $uid, 'Global message completed');
        }
    }

    private function broadcast(string $senderUid, string $message, GlobalMessageType $messageType): int
    {
        $count = 0;
        foreach ($this->users->listConnectedUids() as $targetUid) {
            $this->messages->sendMessage($senderUid, $targetUid, $message, $messageType->value);
            ++$count;
        }

        return $count;
    }
}
