<?php

declare(strict_types=1);

namespace App\Application\OperServ\Command\Handler;

use App\Application\ApplicationPort\ServiceUidRegistry;
use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\OperServ\Command\OperServCommandInterface;
use App\Application\OperServ\Command\OperServContext;
use App\Application\OperServ\Security\OperServPermission;
use App\Application\OperServ\Service\PseudoClientUidGenerator;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Application\Port\SendNoticePort;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\ValueObject\GlobalMessageMask;
use Psr\Log\LoggerInterface;
use ValueError;

use function array_slice;
use function assert;
use function implode;
use function sprintf;
use function strtolower;
use function strtoupper;

final class GlobalCommand implements OperServCommandInterface, IrcopAuditableCommandInterface
{
    private const int DURATION_SECONDS = 86400;

    private const string PRIVMSG = 'PRIVMSG';

    private const string NOTICE = 'NOTICE';

    public function __construct(
        private readonly NetworkUserLookupPort $userLookup,
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly ServiceUidRegistry $serviceUidRegistry,
        private readonly PseudoClientUidGenerator $uidGenerator,
        private readonly ActiveConnectionHolderInterface $connectionHolder,
        private readonly SendNoticePort $sendNoticePort,
        private readonly LoggerInterface $logger,
    ) {}

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
        return 35;
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

    public function getRequiredPermission(): string
    {
        return OperServPermission::GLOBAL;
    }

    public function execute(OperServContext $context): CommandOutcome
    {
        $sender = $context->getSender();
        if (null === $sender) {
            return CommandOutcome::rejected();
        }

        $maskArg = $context->args[0];
        $typeArg = strtoupper($context->args[1]);
        $message = implode(' ', array_slice($context->args, 2));

        if (self::PRIVMSG !== $typeArg && self::NOTICE !== $typeArg) {
            $context->reply('global.type_invalid');

            return CommandOutcome::rejected();
        }

        // Check if this is a service nickname (just nickname, no mask)
        $serviceUid = $this->serviceUidRegistry->getUidByNickname($maskArg);

        if (null !== $serviceUid) {
            // Service nickname - use existing service UID
            return $this->sendFromService($context, $maskArg, $serviceUid, $typeArg, $message);
        }

        // Not a service - must provide full mask nick!ident@vhost
        return $this->sendFromPseudoClient($context, $maskArg, $typeArg, $message);
    }

    /**
     * @param 'NOTICE'|'PRIVMSG' $typeArg
     */
    private function sendFromService(OperServContext $context, string $nickname, string $uid, string $typeArg, string $message): CommandOutcome
    {
        $sender = $context->getSender();
        assert(null !== $sender);

        $this->logger->info('GLOBAL: using existing service', [
            'nickname' => $nickname,
            'uid' => $uid,
            'sender' => $sender->nick,
            'type' => $typeArg,
        ]);

        return $this->broadcastAndReply($context, $nickname, $uid, $typeArg, $message, false);
    }

    /**
     * @param 'NOTICE'|'PRIVMSG' $typeArg
     */
    private function sendFromPseudoClient(OperServContext $context, string $maskArg, string $typeArg, string $message): CommandOutcome
    {
        $sender = $context->getSender();
        assert(null !== $sender);

        try {
            $mask = GlobalMessageMask::fromString($maskArg);
        } catch (ValueError $e) {
            $context->reply('global.mask_invalid', ['%error%' => $e->getMessage()]);

            return CommandOutcome::rejected();
        }

        $nickname = $mask->nickname;

        $serviceOutcome = $this->trySendViaService($context, $nickname, $typeArg, $message);
        if (null !== $serviceOutcome) {
            return $serviceOutcome;
        }

        $errorKey = $this->validatePseudoClientNickname($context, $nickname);
        if (null !== $errorKey) {
            return CommandOutcome::rejected();
        }

        $module = $this->connectionHolder->getProtocolModule();
        $serverSid = $this->connectionHolder->getServerSid();
        if (null !== $module && null !== $serverSid) {
            $uid = $this->uidGenerator->generate();
            if (null === $uid) {
                $this->logger->error('GLOBAL: failed to generate UID');

                return CommandOutcome::rejected();
            }

            $reason = sprintf('Global message pseudo-client (sender: %s)', $sender->nick);

            $module->getNickReservation()?->reserveNickWithDuration($nickname, self::DURATION_SECONDS, $reason);
            $module->getServiceActions()->introducePseudoClient($serverSid, $mask->nickname, $mask->ident, $mask->vhost, $uid, $mask->nickname);

            $this->logger->info('GLOBAL: pseudo-client introduced', [
                'nickname' => $nickname,
                'uid' => $uid,
                'sender' => $sender->nick,
                'type' => $typeArg,
            ]);

            return $this->broadcastAndReply($context, $nickname, $uid, $typeArg, $message, true);
        }
        $this->logger->error('GLOBAL: no active protocol module');

        return CommandOutcome::rejected();
    }

    /**
     * @param 'NOTICE'|'PRIVMSG' $typeArg
     */
    private function trySendViaService(OperServContext $context, string $nickname, string $typeArg, string $message): ?CommandOutcome
    {
        $serviceUid = $this->serviceUidRegistry->getUidByNickname($nickname);
        if (null !== $serviceUid) {
            return $this->sendFromService($context, $nickname, $serviceUid, $typeArg, $message);
        }

        return null;
    }

    private function validatePseudoClientNickname(OperServContext $context, string $nickname): ?string
    {
        $connectedUser = $this->userLookup->findByNick($nickname);
        if (null !== $connectedUser) {
            $context->reply('global.nick_connected', ['%nickname%' => $nickname]);

            return 'connected';
        }

        $registeredNick = $this->nickRepository->findByNick(strtolower($nickname));
        if (null !== $registeredNick) {
            $context->reply('global.nick_registered', ['%nickname%' => $nickname]);

            return 'registered';
        }

        return null;
    }

    /**
     * @param 'NOTICE'|'PRIVMSG' $typeArg
     */
    private function broadcastAndReply(OperServContext $context, string $nickname, string $uid, string $typeArg, string $message, bool $isPseudoClient): CommandOutcome
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

        $context->reply('global.done', ['%nickname%' => $nickname, '%count%' => (string) $count]);

        $sender = $context->getSender();
        $this->logger->info('GLOBAL: message sent', [
            'nickname' => $nickname,
            'uid' => $uid,
            'recipients' => $count,
            'type' => $typeArg,
            'sender' => $sender->nick ?? 'unknown',
        ]);

        return CommandOutcome::success(new IrcopAuditData(
            target: $nickname,
            reason: $message,
            extra: ['type' => $typeArg, 'count' => (string) $count, 'reasonType' => 'message'],
        ));
    }
}
