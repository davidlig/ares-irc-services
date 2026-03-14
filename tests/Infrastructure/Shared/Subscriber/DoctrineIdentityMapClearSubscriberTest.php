<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Shared\Subscriber;

use App\Domain\IRC\Event\IrcMessageProcessedEvent;
use App\Infrastructure\Shared\Subscriber\DoctrineIdentityMapClearSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DoctrineIdentityMapClearSubscriber::class)]
#[AllowMockObjectsWithoutExpectations]
final class DoctrineIdentityMapClearSubscriberTest extends TestCase
{
    #[Test]
    public function getSubscribedEventsReturnsCorrectEvent(): void
    {
        $events = DoctrineIdentityMapClearSubscriber::getSubscribedEvents();

        self::assertArrayHasKey(IrcMessageProcessedEvent::class, $events);
        self::assertSame(['onIrcMessageProcessed', -256], $events[IrcMessageProcessedEvent::class]);
    }

    #[Test]
    public function onIrcMessageProcessedClearsEntityManager(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('clear');

        $subscriber = new DoctrineIdentityMapClearSubscriber($entityManager);

        $subscriber->onIrcMessageProcessed();
    }

    #[Test]
    public function subscriberImplementsEventSubscriberInterface(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $subscriber = new DoctrineIdentityMapClearSubscriber($entityManager);

        self::assertInstanceOf(\Symfony\Component\EventDispatcher\EventSubscriberInterface::class, $subscriber);
    }
}
