<?php

declare(strict_types=1);

namespace App\Application\NickServ\Maintenance;

use App\Application\Maintenance\MaintenanceTaskInterface;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Event\NickDropEvent;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function sprintf;

/**
 * Removes REGISTERED nicknames that have been inactive for more than the configured days.
 * Dispatches NickDropEvent before each deletion so other services (ChanServ, MemoServ) can clean up.
 *
 * Order 200: NickServ account expiry range.
 */
final readonly class PurgeInactiveNicknamesTask implements MaintenanceTaskInterface
{
    public function __construct(
        private readonly RegisteredNickRepositoryInterface $nickRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly int $intervalSeconds,
        private readonly int $inactivityExpiryDays,
    ) {
    }

    public function getName(): string
    {
        return 'nickserv.purge_inactive_nicknames';
    }

    public function getIntervalSeconds(): int
    {
        return $this->intervalSeconds;
    }

    public function getOrder(): int
    {
        return 200;
    }

    public function run(): void
    {
        if ($this->inactivityExpiryDays <= 0) {
            return;
        }

        $threshold = (new DateTimeImmutable())->modify(sprintf('-%d days', $this->inactivityExpiryDays));
        $inactive = $this->nickRepository->findRegisteredInactiveSince($threshold);

        foreach ($inactive as $nick) {
            if (!$nick instanceof RegisteredNick) {
                continue;
            }

            $nickId = $nick->getId();
            $nickname = $nick->getNickname();
            $nicknameLower = $nick->getNicknameLower();
            $lastActivity = $nick->getLastSeenAt() ?? $nick->getRegisteredAt();
            $lastActivityStr = null !== $lastActivity ? $lastActivity->format('Y-m-d H:i:s') : 'n/a';

            $this->eventDispatcher->dispatch(new NickDropEvent(
                $nickId,
                $nickname,
                $nicknameLower,
                'inactivity',
            ));

            $this->nickRepository->delete($nick);

            $this->logger->info(
                sprintf(
                    'Maintenance [%s]: deleted nickname %s (id %d) due to inactivity (last activity: %s).',
                    $this->getName(),
                    $nickname,
                    $nickId,
                    $lastActivityStr,
                ),
            );
        }
    }
}
