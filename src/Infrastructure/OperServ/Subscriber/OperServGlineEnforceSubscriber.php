<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Subscriber;

use App\Application\Port\ActiveConnectionHolderInterface;
use App\Domain\IRC\Event\NetworkSyncCompleteEvent;
use App\Domain\OperServ\Entity\Gline;
use App\Domain\OperServ\Repository\GlineRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;

final readonly class OperServGlineEnforceSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private GlineRepositoryInterface $glineRepository,
        private ActiveConnectionHolderInterface $connectionHolder,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkSyncCompleteEvent::class => ['onSyncComplete', 0],
        ];
    }

    public function onSyncComplete(NetworkSyncCompleteEvent $event): void
    {
        $glines = $this->glineRepository->findActive();

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

    private function sendGline(Gline $gline, string $serverSid, object $serviceActions): void
    {
        $parts = Gline::parseUserHost($gline->getMask());
        $duration = null === $gline->getExpiresAt()
            ? 0
            : max(0, $gline->getExpiresAt()->getTimestamp() - time());

        $serviceActions->addGline(
            $serverSid,
            $parts['user'],
            $parts['host'],
            $duration,
            $gline->getReason() ?? 'No reason provided',
        );

        $this->logger->debug('GLINE reapplied', [
            'mask' => $gline->getMask(),
            'duration' => $duration,
        ]);
    }
}
