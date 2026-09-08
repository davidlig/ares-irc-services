<?php

declare(strict_types=1);

namespace App\Tests\Irc\Adapter\In\Event;

use App\Irc\Adapter\In\Event\CommandExecutedAuditSubscriber;
use App\Irc\Application\Port\In\Command\CommandOutcome as PublishedCommandOutcome;
use App\Irc\Application\Port\In\Command\IrcopAuditableCommandInterface as PublishedIrcopAuditableCommandInterface;
use App\Irc\Application\Port\In\Command\IrcopAuditData as PublishedIrcopAuditData;
use App\Irc\Application\PublishedEvent\CommandExecutedEvent as PublishedCommandExecutedEvent;
use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(CommandExecutedAuditSubscriber::class)]
final class CommandExecutedAuditSubscriberTest extends TestCase
{
    #[Test]
    public function subscribesToPublishedCommandExecutions(): void
    {
        self::assertSame([
            PublishedCommandExecutedEvent::class => 'onPublishedCommandExecuted',
        ], CommandExecutedAuditSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function classifiesPublishedRootMutationAsRootAdministration(): void
    {
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (CommandAuditRecord $record): bool => CommandAuditCategory::RootAdministration === $record->category
                && 'ROOT' === $record->permission
                && 'Alice' === $record->target,
        ));

        $event = new PublishedCommandExecutedEvent(
            new class implements PublishedIrcopAuditableCommandInterface {},
            'operserv',
            'RootAdmin',
            'IRCOP',
            'ROOT',
            PublishedCommandOutcome::success(new PublishedIrcopAuditData('Alice')),
        );

        new CommandExecutedAuditSubscriber($audit)->onPublishedCommandExecuted($event);
    }

    #[Test]
    public function ignoresNonAuditableRejectedIncompleteAndUnclassifiedPublishedEvents(): void
    {
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $subscriber = new CommandExecutedAuditSubscriber($audit);
        $auditable = new class implements PublishedIrcopAuditableCommandInterface {};

        $subscriber->onPublishedCommandExecuted($this->publishedEvent(new stdClass(), 'operserv.raw', PublishedCommandOutcome::success(new PublishedIrcopAuditData('KILL'))));
        $subscriber->onPublishedCommandExecuted($this->publishedEvent($auditable, 'operserv.raw', PublishedCommandOutcome::rejected()));
        $subscriber->onPublishedCommandExecuted($this->publishedEvent($auditable, 'operserv.raw', PublishedCommandOutcome::success()));
        $subscriber->onPublishedCommandExecuted($this->publishedEvent($auditable, null, PublishedCommandOutcome::success(new PublishedIrcopAuditData('KILL'))));
        $subscriber->onPublishedCommandExecuted($this->publishedEvent($auditable, 'operserv.raw', null));
    }

    private function publishedEvent(object $command, ?string $permission, ?PublishedCommandOutcome $outcome): PublishedCommandExecutedEvent
    {
        return new PublishedCommandExecutedEvent($command, 'operserv', 'Oper', 'RAW', $permission, $outcome);
    }
}
