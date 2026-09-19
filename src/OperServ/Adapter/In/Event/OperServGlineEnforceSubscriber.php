<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Event;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\Irc\Application\PublishedEvent\NetworkSynchronizationCompletedEvent;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Domain\Entity\Gline;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;

final readonly class OperServGlineEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private GlineRepository $glineRepository,
        private ActiveProtocolModuleHolderInterface $connectionHolder,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSynchronizationCompletedEvent::class => ['onSyncComplete', 0],
        ];
    }

    public function onSyncComplete(NetworkSynchronizationCompletedEvent $event): void
    {
        $glines = $this->glineRepository->findActiveAt(new DateTimeImmutable());

        if ([] === $glines) {
            return;
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->warning('GLINE enforce: no active protocol module');

            return;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        if (null === $serverSid) {
            $this->logger->warning('GLINE enforce: no server SID');

            return;
        }

        $serviceActions = $module->getServiceActions();

        foreach ($glines as $gline) {
            $this->sendGline($gline, $serverSid, $serviceActions);
        }

        $this->logger->info('GLINEs reapplied after sync', [
            'count' => count($glines),
        ]);
    }

    private function sendGline(GlineEntry $gline, string $serverSid, ProtocolServiceActionsInterface $serviceActions): void
    {
        $parts = Gline::parseUserHost($gline->mask);
        $duration = null === $gline->expiresAt
            ? 0
            : max(0, $gline->expiresAt->getTimestamp() - time());

        $serviceActions->addGline(
            $serverSid,
            $parts['user'],
            $parts['host'],
            $duration,
            $gline->reason ?? 'No reason provided',
        );

        $this->logger->debug('GLINE reapplied', [
            'mask' => $gline->mask,
            'duration' => $duration,
        ]);
    }
}
