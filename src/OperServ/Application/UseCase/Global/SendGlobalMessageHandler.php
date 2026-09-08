<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Global;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\Out\GlobalMessageTransport;
use App\OperServ\Application\Port\Out\NetworkUserLookup;
use App\OperServ\Application\Port\Out\OperatorAccountLookup;
use App\OperServ\Domain\ValueObject\GlobalMessageMask;
use ValueError;

use function strtolower;
use function strtoupper;

/** Orchestrates GLOBAL delivery while keeping IRCd lifecycle operations behind one output port. */
final readonly class SendGlobalMessageHandler
{
    public function __construct(
        private NetworkUserLookup $users,
        private OperatorAccountLookup $nickAccounts,
        private GlobalMessageTransport $network,
    ) {}

    public function handle(SendGlobalMessage $command): SendGlobalMessageResult
    {
        $messageType = GlobalMessageType::tryFrom(strtoupper($command->messageType));
        if (null === $messageType) {
            return SendGlobalMessageResult::invalidMessageType();
        }

        $serviceUid = $this->network->serviceUidForNickname($command->senderMaskOrServiceNickname);
        if (null !== $serviceUid) {
            $count = $this->network->broadcastFromService($serviceUid, $command->message, $messageType);

            return null === $count
                ? SendGlobalMessageResult::networkUnavailable($command->senderMaskOrServiceNickname)
                : $this->sent($command, $command->senderMaskOrServiceNickname, $messageType, $count, 'service');
        }

        try {
            $sender = GlobalMessageMask::fromString($command->senderMaskOrServiceNickname);
        } catch (ValueError $error) {
            return SendGlobalMessageResult::invalidMask($error->getMessage());
        }

        $serviceUid = $this->network->serviceUidForNickname($sender->nickname);
        if (null !== $serviceUid) {
            $count = $this->network->broadcastFromService($serviceUid, $command->message, $messageType);

            return null === $count
                ? SendGlobalMessageResult::networkUnavailable($sender->nickname)
                : $this->sent($command, $sender->nickname, $messageType, $count, 'service');
        }

        if (null !== $this->users->findByNickname($sender->nickname)) {
            return SendGlobalMessageResult::nicknameConnected($sender->nickname);
        }

        if (null !== $this->nickAccounts->findIdByNickname(strtolower($sender->nickname))) {
            return SendGlobalMessageResult::nicknameRegistered($sender->nickname);
        }

        $count = $this->network->broadcastFromTemporaryClient($sender, $command->message, $messageType, $command->actorNickname);

        return null === $count
            ? SendGlobalMessageResult::networkUnavailable($sender->nickname)
            : $this->sent($command, $sender->nickname, $messageType, $count, 'temporary_client');
    }

    private function sent(SendGlobalMessage $command, string $nickname, GlobalMessageType $messageType, int $count, string $senderKind): SendGlobalMessageResult
    {
        return SendGlobalMessageResult::sent(
            $nickname,
            $count,
            new CommandAuditRecord(
                category: CommandAuditCategory::OperatorAction,
                service: 'OperServ',
                actor: $command->actorNickname,
                operation: 'GLOBAL',
                occurredAt: $command->occurredAt,
                target: $nickname,
                permission: 'operserv.global',
                metadata: [
                    'message_type' => $messageType->value,
                    'recipient_count' => $count,
                    'sender_kind' => $senderKind,
                ],
            ),
        );
    }
}
