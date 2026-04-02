<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\ApplicationPort\ServiceUidRegistry;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\OperServ\Service\PseudoClientUidGenerator;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\DebugActionPort;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SendNoticePort;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\ValueObject\GlobalMessageMask;
use Psr\Log\LoggerInterface;
use ValueError;

use function array_slice;
use function implode;
use function sprintf;
use function strtolower;
use function strtoupper;

final readonly class GlobalCommand implements OperServCommandInterface
{
    private const int DURATION_SECONDS = 86400; // 1 day

    private const string PRIVMSG = 'PRIVMSG';

    private const string NOTICE = 'NOTICE';

    public function __construct(
        private NetworkUserLookupPort $userLookup,
        private RegisteredNickRepositoryInterface $nickRepository,
        private ServiceUidRegistry $serviceUidRegistry,
        private PseudoClientUidGenerator $uidGenerator,
        private ActiveConnectionHolderInterface $connectionHolder,
        private SendNoticePort $sendNoticePort,
        private DebugActionPort $debug,
        private LoggerInterface $logger,
    ) {
    }

    public function getName(): string
    {
        return 'GLOBAL';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getMinArgs(): int
    {
        return 3;
    }

    public function getSyntaxKey(): string
    {
        return 'global.syntax';
    }

    public function getHelpKey(): string
    {
        return 'global.help';
    }

    public function getOrder(): int
    {
        return 50;
    }

    public function getShortDescKey(): string
    {
        return 'global.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return OperServPermission::GLOBAL;
    }

    public function execute(OperServContext $context): void
    {
        $sender = $context->getSender();
        if (null === $sender) {
            return;
        }

        $maskArg = $context->args[0];
        $typeArg = strtoupper($context->args[1]);
        $message = implode(' ', array_slice($context->args, 2));

        if (self::PRIVMSG !== $typeArg && self::NOTICE !== $typeArg) {
            $context->reply('global.type_invalid');

            return;
        }

        // Check if this is a service nickname (just nickname, no mask)
        $serviceUid = $this->serviceUidRegistry->getUidByNickname($maskArg);

        if (null !== $serviceUid) {
            // Service nickname - use existing service UID
            $this->sendFromService($context, $maskArg, $serviceUid, $typeArg, $message);
        } else {
            // Not a service - must provide full mask nick!ident@vhost
            $this->sendFromPseudoClient($context, $maskArg, $typeArg, $message);
        }
    }

    private function sendFromService(OperServContext $context, string $nickname, string $uid, string $typeArg, string $message): void
    {
        $sender = $context->getSender();
        if (null === $sender) {
            return;
        }

        $this->logger->info('GLOBAL: using existing service', [
            'nickname' => $nickname,
            'uid' => $uid,
            'sender' => $sender->nick,
            'type' => $typeArg,
        ]);

        $this->broadcastAndReply($context, $nickname, $uid, $typeArg, $message, false);
    }

    private function sendFromPseudoClient(OperServContext $context, string $maskArg, string $typeArg, string $message): void
    {
        $sender = $context->getSender();
        if (null === $sender) {
            return;
        }

        try {
            $mask = GlobalMessageMask::fromString($maskArg);
        } catch (ValueError $e) {
            $context->reply('global.mask_invalid', ['%error%' => $e->getMessage()]);

            return;
        }

        $nickname = $mask->nickname;
        $nicknameLower = strtolower($nickname);

        // Check if the nickname extracted from mask is a service
        $serviceUid = $this->serviceUidRegistry->getUidByNickname($nickname);
        if (null !== $serviceUid) {
            // Use the existing service instead of creating pseudo-client
            $this->sendFromService($context, $nickname, $serviceUid, $typeArg, $message);

            return;
        }

        // Validate nickname is not connected or registered
        $connectedUser = $this->userLookup->findByNick($nickname);
        if (null !== $connectedUser) {
            $context->reply('global.nick_connected', ['%nick%' => $nickname]);

            return;
        }

        $registeredNick = $this->nickRepository->findByNick($nicknameLower);
        if (null !== $registeredNick) {
            $context->reply('global.nick_registered', ['%nick%' => $nickname]);

            return;
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->error('GLOBAL: no active protocol module');

            return;
        }

        $nickReservation = $module->getNickReservation();
        if (null === $nickReservation) {
            $this->logger->error('GLOBAL: protocol does not support nick reservation');

            return;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            $this->logger->error('GLOBAL: no server SID');

            return;
        }

        $connection = $this->connectionHolder->getConnection();
        if (null === $connection) {
            $this->logger->error('GLOBAL: no connection');

            return;
        }

        $uid = $this->uidGenerator->generate();
        if (null === $uid) {
            $this->logger->error('GLOBAL: could not generate UID');

            return;
        }

        $reason = sprintf('Global message pseudo-client (sender: %s)', $sender->nick);

        $nickReservation->reserveNickWithDuration($connection, $serverSid, $nickname, self::DURATION_SECONDS, $reason);
        $module->getServiceActions()->introducePseudoClient($serverSid, $mask->nickname, $mask->ident, $mask->vhost, $uid, $mask->nickname);

        $this->logger->info('GLOBAL: pseudo-client introduced', [
            'nickname' => $nickname,
            'uid' => $uid,
            'sender' => $sender->nick,
            'type' => $typeArg,
        ]);

        $this->broadcastAndReply($context, $nickname, $uid, $typeArg, $message, true);
    }

    private function broadcastAndReply(OperServContext $context, string $nickname, string $uid, string $typeArg, string $message, bool $isPseudoClient): void
    {
        $uids = $this->userLookup->listConnectedUids();
        $count = 0;

        foreach ($uids as $targetUid) {
            $this->sendNoticePort->sendMessage($uid, $targetUid, $message, $typeArg);
            ++$count;
        }

        if ($isPseudoClient) {
            $module = $this->connectionHolder->getProtocolModule();
            $serverSid = $this->connectionHolder->getServerSid();
            if (null !== $module && null !== $serverSid) {
                $module->getServiceActions()->quitPseudoClient($serverSid, $uid, 'Global message completed');
            }
        }

        $context->reply('global.done', ['%nick%' => $nickname, '%count%' => (string) $count]);

        $sender = $context->getSender();
        $this->logger->info('GLOBAL: message sent', [
            'nickname' => $nickname,
            'uid' => $uid,
            'recipients' => $count,
            'type' => $typeArg,
            'sender' => $sender?->nick ?? 'unknown',
        ]);

        // Log debug action for IRCop audit
        $this->debug->log(
            operator: $sender?->nick ?? 'unknown',
            command: 'GLOBAL',
            target: $nickname,
            reason: $message,
            extra: ['type' => $typeArg, 'count' => (string) $count, 'reasonType' => 'message'],
        );
    }
}
