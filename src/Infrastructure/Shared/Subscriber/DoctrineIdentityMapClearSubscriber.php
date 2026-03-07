<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Subscriber;

use App\Domain\IRC\Event\IrcMessageProcessedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears the Doctrine Identity Map after each IRC message cycle to prevent
 * unbounded memory growth in long-running daemons (regla 9 - .cursorrules).
 *
 * Subscribes to IrcMessageProcessedEvent with priority -256 so it runs after
 * all other subscribers (e.g. ChanServChannelRankSubscriber at -255).
 */
final readonly class DoctrineIdentityMapClearSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            IrcMessageProcessedEvent::class => ['onIrcMessageProcessed', -256],
        ];
    }

    public function onIrcMessageProcessed(): void
    {
        $this->entityManager->clear();
    }
}
