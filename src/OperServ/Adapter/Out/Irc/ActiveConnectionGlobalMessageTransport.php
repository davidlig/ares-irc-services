<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Irc;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SendNoticePort;
use App\Irc\Application\Port\In\ServiceUidRegistry;
use App\OperServ\Application\Model\MessageDelivery;
use App\OperServ\Application\Port\Out\GlobalMessageTransport;
use App\OperServ\Domain\ValueObject\GlobalMessageMask;

use function sprintf;

/** Bridges GLOBAL's semantic broadcast lifecycle to the active IRC protocol adapter. */
final readonly class ActiveConnectionGlobalMessageTransport implements GlobalMessageTransport
{
    private const int RESERVATION_DURATION_SECONDS = 86400;

    public function __construct(
        private ServiceUidRegistry $serviceUids,
        private PseudoClientUidGenerator $pseudoClientUids,
        private ActiveProtocolModuleHolderInterface $connection,
        private NetworkUserLookupPort $users,
        private SendNoticePort $messages,
    ) {}

    public function serviceUidForNickname(string $nickname): ?string
    {
        return $this->serviceUids->getUidByNickname($nickname);
    }

    public function broadcastFromService(string $senderUid, string $message, MessageDelivery $delivery): ?int
    {
        if (!$this->connection->isConnected()) {
            return null;
        }

        return $this->broadcast($senderUid, $message, $delivery);
    }

    public function broadcastFromTemporaryClient(GlobalMessageMask $sender, string $message, MessageDelivery $delivery, string $actorNickname): ?int
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
            return $this->broadcast($uid, $message, $delivery);
        } finally {
            $module->getServiceActions()->quitPseudoClient($serverSid, $uid, 'Global message completed');
        }
    }

    private function broadcast(string $senderUid, string $message, MessageDelivery $delivery): int
    {
        $count = 0;
        foreach ($this->users->listConnectedUids() as $targetUid) {
            $this->messages->sendMessage($senderUid, $targetUid, $message, match ($delivery) {
                MessageDelivery::NonInteractive => 'NOTICE',
                MessageDelivery::Interactive => 'PRIVMSG',
            });
            ++$count;
        }

        return $count;
    }
}
