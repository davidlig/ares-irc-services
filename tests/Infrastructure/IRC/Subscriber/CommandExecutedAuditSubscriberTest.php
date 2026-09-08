<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Subscriber;

use App\Application\Command\CommandOutcome;
use App\Application\Command\IrcopAuditableCommandInterface;
use App\Application\Command\IrcopAuditData;
use App\Application\Event\CommandExecutedEvent;
use App\Infrastructure\IRC\Subscriber\CommandExecutedAuditSubscriber;
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
    public function subscribesToLegacyAndPublishedCommandExecutions(): void
    {
        self::assertSame([
            CommandExecutedEvent::class => 'onCommandExecuted',
            PublishedCommandExecutedEvent::class => 'onPublishedCommandExecuted',
        ], CommandExecutedAuditSubscriber::getSubscribedEvents());
    }

    #[Test]
    public function recordsSuccessfulLegacyOperatorAction(): void
    {
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::once())->method('record')->with(self::callback(
            static fn (CommandAuditRecord $record): bool => CommandAuditCategory::OperatorAction === $record->category
                && 'operserv' === $record->service
                && 'Oper' === $record->actor
                && 'RAW' === $record->operation
                && 'operserv.raw' === $record->permission
                && 'KILL' === $record->target
                && 'ident@host' === $record->targetHost
                && '127.0.0.1' === $record->targetIp
                && 'reason' === $record->reason
                && ['transport' => 'irc'] === $record->metadata,
        ));

        $event = new CommandExecutedEvent(
            new class implements IrcopAuditableCommandInterface {},
            'operserv',
            'Oper',
            'RAW',
            'operserv.raw',
            CommandOutcome::success(new IrcopAuditData('KILL', 'ident@host', '127.0.0.1', 'reason', ['transport' => 'irc'])),
        );

        new CommandExecutedAuditSubscriber($audit)->onCommandExecuted($event);
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
    public function ignoresNonAuditableRejectedIncompleteAndUnclassifiedLegacyEvents(): void
    {
        $audit = $this->createMock(CommandAuditRecorder::class);
        $audit->expects(self::never())->method('record');
        $subscriber = new CommandExecutedAuditSubscriber($audit);
        $auditable = new class implements IrcopAuditableCommandInterface {};

        $subscriber->onCommandExecuted($this->legacyEvent(new stdClass(), 'operserv.raw', CommandOutcome::success(new IrcopAuditData('KILL'))));
        $subscriber->onCommandExecuted($this->legacyEvent($auditable, 'operserv.raw', CommandOutcome::rejected()));
        $subscriber->onCommandExecuted($this->legacyEvent($auditable, 'operserv.raw', CommandOutcome::success()));
        $subscriber->onCommandExecuted($this->legacyEvent($auditable, null, CommandOutcome::success(new IrcopAuditData('KILL'))));
        $subscriber->onCommandExecuted($this->legacyEvent($auditable, 'operserv.raw', null));
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

    private function legacyEvent(object $command, ?string $permission, ?CommandOutcome $outcome): CommandExecutedEvent
    {
        return new CommandExecutedEvent($command, 'operserv', 'Oper', 'RAW', $permission, $outcome);
    }

    private function publishedEvent(object $command, ?string $permission, ?PublishedCommandOutcome $outcome): PublishedCommandExecutedEvent
    {
        return new PublishedCommandExecutedEvent($command, 'operserv', 'Oper', 'RAW', $permission, $outcome);
    }
}
