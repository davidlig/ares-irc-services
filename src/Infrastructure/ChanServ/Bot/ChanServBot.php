<?php

declare(strict_types=1);

namespace App\Infrastructure\ChanServ\Bot;

use App\Application\ApplicationPort\ServiceNicknameProviderInterface;
use App\Application\ApplicationPort\ServiceUidGeneratorInterface;
use App\Application\ApplicationPort\ServiceUidProviderInterface;
use App\Application\ChanServ\Command\ChanServNotifierInterface;
use App\Application\Port\ApplyOutgoingChannelModesPort;
use App\Application\Port\ChannelLookupPort;
use App\Application\Port\ChannelServiceActionsPort;
use App\Application\Port\SendNoticePort;
use App\Application\Port\ServiceChannelRegistrationPort;
use App\Domain\IRC\Connection\ConnectionInterface;
use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Infrastructure\IRC\Connection\ActiveConnectionHolder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function in_array;

/**
 * ChanServ pseudo-client: introduces on burst, implements ChanServNotifierInterface
 * and ChannelServiceActionsPort. Delegates channel actions to the active protocol module.
 */
final class ChanServBot implements ChanServNotifierInterface, ChannelServiceActionsPort, ServiceNicknameProviderInterface, ServiceUidProviderInterface, EventSubscriberInterface
{
    /** Preferred order of prefix modes (highest first). */
    private const array PREFIX_ORDER = ['q', 'a', 'o', 'h', 'v'];

    private string $uid = '';

    public function __construct(
        private readonly ActiveConnectionHolder $connectionHolder,
        private readonly ChannelLookupPort $channelLookup,
        private readonly ApplyOutgoingChannelModesPort $applyOutgoingChannelModes,
        private readonly ServiceChannelRegistrationPort $channelRegistration,
        private readonly SendNoticePort $sendNoticePort,
        private readonly ServiceUidGeneratorInterface $uidGenerator,
        private readonly string $servicesVhost,
        private readonly string $chanservNick = 'ChanServ',
        private readonly string $chanservIdent = 'ChanServ',
        private readonly string $chanservRealname = 'Channel Registration Services',
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 95],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        $this->uid = $this->uidGenerator->generateUid('chanserv');
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
            $this->chanservNick,
            $this->chanservIdent,
            $this->servicesVhost,
            $this->uid,
            $this->chanservRealname,
            $this->getServiceKey(),
        );

        $this->logger->info('ChanServ introduced to network.', [
            'uid' => $this->uid,
            'nick' => $this->chanservNick,
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

    public function sendNoticeToChannel(string $channelName, string $message): void
    {
        $channelView = $this->channelLookup->findByChannelName($channelName);
        if (null === $channelView || $channelView->memberCount < 1) {
            return;
        }

        $this->sendNoticePort->sendNoticeToChannel($this->uid, $channelName, $message);
    }

    public function setChannelModes(string $channelName, string $modeStr, array $params = [], ?int $channelTimestamp = null): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null !== $module && '' !== $sid) {
            $view = $this->channelLookup->findByChannelName($channelName);
            $channelTimestamp ??= $view?->timestamp;
            $module->getServiceActions()->setChannelModes($sid, $channelName, $modeStr, $params, $this->uid, $channelTimestamp);
            $this->applyOutgoingChannelModes->applyOutgoingChannelModes($channelName, $modeStr, $params);
        }
    }

    public function setChannelMemberMode(string $channelName, string $targetUid, string $modeLetter, bool $add, ?int $channelTimestamp = null): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null !== $module && '' !== $sid) {
            $view = $this->channelLookup->findByChannelName($channelName);
            $channelTimestamp ??= $view?->timestamp;
            $module->getServiceActions()->setChannelMemberMode($sid, $channelName, $targetUid, $modeLetter, $add, $this->uid, $channelTimestamp);
        }
    }

    public function inviteToChannel(string $channelName, string $targetUid, ?int $channelTimestamp = null): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null !== $module && '' !== $sid) {
            if (null === $channelTimestamp) {
                $view = $this->channelLookup->findByChannelName($channelName);
                $channelTimestamp = $view?->timestamp;
            }
            $module->getServiceActions()->inviteUserToChannel($sid, $channelName, $targetUid, $this->uid, $channelTimestamp);
        }
    }

    public function joinChannelAsService(string $channelName, ?int $channelTimestamp = null): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null === $module || '' === $sid) {
            return;
        }

        $supported = $module->getChannelModeSupport()->getSupportedPrefixModes();
        $maxPrefix = array_find(self::PREFIX_ORDER, static fn ($letter) => in_array($letter, $supported, true)) ?? 'o';

        $actualTimestamp = $channelTimestamp;
        if (null === $actualTimestamp) {
            $view = $this->channelLookup->findByChannelName($channelName);
            $actualTimestamp = $view->timestamp ?? time();
        }

        $module->getServiceActions()->joinChannelAsService($sid, $channelName, $this->uid, $maxPrefix, $actualTimestamp);

        $this->channelRegistration->registerServiceChannelJoin(
            $channelName,
            $this->uid,
            $maxPrefix,
            $actualTimestamp,
        );
    }

    public function setChannelTopic(string $channelName, ?string $topic, ?int $channelCreationTs = null): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null !== $module && '' !== $sid) {
            $creationTs = $channelCreationTs;
            if (null === $creationTs) {
                $view = $this->channelLookup->findByChannelName($channelName);
                $creationTs = $view?->timestamp;
            }
            $module->getServiceActions()->setChannelTopic($sid, $channelName, $topic, $this->uid, $creationTs);
        }
    }

    public function kickFromChannel(string $channelName, string $targetUid, string $reason): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null !== $module && '' !== $sid) {
            $module->getServiceActions()->kickFromChannel($sid, $channelName, $targetUid, $reason, $this->uid);
        }
    }

    public function partChannelAsService(string $channelName): void
    {
        $module = $this->connectionHolder->getProtocolModule();
        $sid = $this->connectionHolder->getServerSid() ?? '';
        if (null !== $module && '' !== $sid) {
            $module->getServiceActions()->partChannelAsService($sid, $channelName, $this->uid);
            $this->channelRegistration->unregisterServiceChannelPart($channelName, $this->uid);
        }
    }

    public function getNick(): string
    {
        return $this->chanservNick;
    }

    public function getUid(): string
    {
        return $this->uid;
    }

    public function getServiceKey(): string
    {
        return 'chanserv';
    }

    public function getNickname(): string
    {
        return $this->chanservNick;
    }
}
