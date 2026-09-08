<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageMotd;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\MotdEntry;
use App\OperServ\Application\Port\Out\MotdRepository;
use DateInterval;
use DateTimeImmutable;

use function count;
use function ctype_digit;
use function preg_match;
use function strtoupper;

final readonly class ManageMotdHandler implements ManageMotdHandlerInterface
{
    public function __construct(
        private MotdRepository $motds,
        private CommandAuditRecorder $audit,
    ) {}

    public function handle(ManageMotd $command): ManageMotdResult
    {
        return match ($command->action) {
            MotdAction::Add => $this->add($command),
            MotdAction::Delete => $this->delete($command),
            MotdAction::List => $this->list($command),
            MotdAction::Clean => $this->clean($command),
            MotdAction::Unknown => new ManageMotdResult(ManageMotdOutcome::UnknownAction),
        };
    }

    private function add(ManageMotd $command): ManageMotdResult
    {
        $botNickname = $command->botNickname ?? '';
        $messageType = strtoupper($command->messageType ?? '');
        $duration = $command->expiry ?? '';
        $text = $command->text ?? '';
        if ('' === $botNickname || '' === $messageType || '' === $duration || '' === $text) {
            return new ManageMotdResult(ManageMotdOutcome::InvalidAddRequest);
        }
        if ('NOTICE' !== $messageType && 'PRIVMSG' !== $messageType) {
            return new ManageMotdResult(ManageMotdOutcome::InvalidMessageType);
        }

        $expiresAt = $this->expiresAt($duration, $command->occurredAt);
        if (null === $expiresAt && '0' !== $duration) {
            return new ManageMotdResult(ManageMotdOutcome::InvalidExpiry);
        }

        $entry = $this->motds->add(
            $text,
            $botNickname,
            $messageType,
            $command->actorAccountId,
            $command->occurredAt,
            $expiresAt,
        );
        $this->recordAudit($command, 'MOTD ADD', $botNickname, ['motd_id' => $entry->id]);

        return new ManageMotdResult(ManageMotdOutcome::Added, $entry->id, $botNickname);
    }

    private function delete(ManageMotd $command): ManageMotdResult
    {
        $id = $command->id ?? '';
        if (!ctype_digit($id)) {
            return new ManageMotdResult(ManageMotdOutcome::InvalidId);
        }

        $entry = $this->motds->findById((int) $id);
        if (null === $entry) {
            return new ManageMotdResult(ManageMotdOutcome::NotFound);
        }

        $this->motds->remove($entry);
        $this->recordAudit($command, 'MOTD DEL', $entry->botNickname, ['motd_id' => $entry->id]);

        return new ManageMotdResult(ManageMotdOutcome::Deleted, $entry->id, $entry->botNickname);
    }

    private function list(ManageMotd $command): ManageMotdResult
    {
        $entries = $this->motds->findAll();
        if ([] === $entries) {
            return new ManageMotdResult(ManageMotdOutcome::ListEmpty);
        }

        return new ManageMotdResult(
            ManageMotdOutcome::Listed,
            entries: array_map(fn (MotdEntry $entry): MotdListEntry => $this->listEntry($entry, $command->occurredAt), $entries),
        );
    }

    private function clean(ManageMotd $command): ManageMotdResult
    {
        $entries = $this->motds->findExpiredAt($command->occurredAt);
        if ([] === $entries) {
            return new ManageMotdResult(ManageMotdOutcome::CleanEmpty);
        }

        foreach ($entries as $entry) {
            $this->motds->remove($entry);
        }
        $this->recordAudit($command, 'MOTD CLEAN', null, ['removed_count' => count($entries)]);

        return new ManageMotdResult(ManageMotdOutcome::Cleaned, removedCount: count($entries));
    }

    private function expiresAt(string $duration, DateTimeImmutable $occurredAt): ?DateTimeImmutable
    {
        if ('0' === $duration) {
            return null;
        }

        $matches = [];
        if (1 !== preg_match('/^(\d+)([smhd])$/i', $duration, $matches)) {
            return null;
        }

        $seconds = match (strtolower($matches[2])) {
            'm' => (int) $matches[1] * 60,
            'h' => (int) $matches[1] * 3600,
            'd' => (int) $matches[1] * 86400,
            default => (int) $matches[1],
        };

        return $occurredAt->add(new DateInterval('PT' . $seconds . 'S'));
    }

    private function listEntry(MotdEntry $entry, DateTimeImmutable $occurredAt): MotdListEntry
    {
        return new MotdListEntry(
            $entry->id,
            $entry->text,
            $entry->botNickname,
            $entry->messageType,
            $entry->enabled,
            $entry->isExpiredAt($occurredAt),
            $entry->expiresAt,
            $entry->shownCount,
        );
    }

    /** @param array<string, int> $metadata */
    private function recordAudit(ManageMotd $command, string $operation, ?string $target, array $metadata): void
    {
        $this->audit->record(new CommandAuditRecord(
            CommandAuditCategory::OperatorAction,
            'operserv',
            $command->actor,
            $operation,
            $command->occurredAt,
            $target,
            permission: 'operserv.motd',
            metadata: $metadata,
        ));
    }
}
