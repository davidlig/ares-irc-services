<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGline;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\GlineEntry;
use App\OperServ\Application\Port\Out\GlineNetworkActions;
use App\OperServ\Application\Port\Out\GlineRepository;
use App\OperServ\Application\Port\Out\GlineUser;
use App\OperServ\Application\Port\Out\GlineUserLookup;
use App\OperServ\Application\Port\Out\ProtectedGlineSubjects;
use App\OperServ\Domain\Policy\GlineMaskPolicy;
use App\Shared\Application\Time\RelativeExpiryParser;

use function ctype_digit;
use function fnmatch;
use function strpos;
use function strtolower;
use function substr;

final readonly class ManageGlineHandler implements ManageGlineHandlerInterface
{
    public function __construct(
        private GlineRepository $glines,
        private GlineUserLookup $users,
        private ProtectedGlineSubjects $protectedSubjects,
        private GlineNetworkActions $network,
        private CommandAuditRecorder $audit,
        private int $maxGlines = 1000,
    ) {}

    public function handle(ManageGline $command): ManageGlineResult
    {
        return match ($command->action) {
            GlineAction::Add => $this->add($command),
            GlineAction::Delete => $this->delete($command),
            GlineAction::List => $this->list($command),
            GlineAction::Unknown => new ManageGlineResult(ManageGlineOutcome::UnknownAction),
        };
    }

    private function add(ManageGline $command): ManageGlineResult
    {
        $mask = $command->mask ?? '';
        $reason = $command->reason ?? '';
        $expiry = $command->expiry ?? '';
        if ('' === $mask || '' === $reason || '' === $expiry) {
            return new ManageGlineResult(ManageGlineOutcome::InvalidRequest);
        }
        if (!GlineMaskPolicy::isValidInput($mask)) {
            return new ManageGlineResult(ManageGlineOutcome::InvalidMask);
        }

        $resolvedMask = $mask;
        if (GlineMaskPolicy::isNickname($mask)) {
            $user = $this->users->findByNickname($mask);
            if (null === $user) {
                return new ManageGlineResult(ManageGlineOutcome::UserNotFound, mask: $mask);
            }
            $resolvedMask = $user->ident . '@' . $user->hostname;
        }
        if (GlineMaskPolicy::isGlobal($resolvedMask)) {
            return new ManageGlineResult(ManageGlineOutcome::GlobalMask, mask: $resolvedMask);
        }
        if (!GlineMaskPolicy::isSafe($resolvedMask)) {
            return new ManageGlineResult(ManageGlineOutcome::DangerousMask, mask: $resolvedMask);
        }

        $protectedNickname = $this->protectedNicknameMatching($resolvedMask);
        if (null !== $protectedNickname) {
            return new ManageGlineResult(ManageGlineOutcome::ProtectedUser, mask: $resolvedMask, protectedNickname: $protectedNickname);
        }

        $expiresAt = RelativeExpiryParser::isPermanent($expiry) ? null : RelativeExpiryParser::parse($expiry, $command->occurredAt);
        if (null === $expiresAt && !RelativeExpiryParser::isPermanent($expiry)) {
            return new ManageGlineResult(ManageGlineOutcome::InvalidExpiry);
        }

        $existing = $this->glines->findByMask($resolvedMask);
        if (null !== $existing) {
            if (!$existing->isExpiredAt($command->occurredAt)) {
                return new ManageGlineResult(ManageGlineOutcome::AlreadyExists, mask: $resolvedMask);
            }
            $this->glines->remove($existing);
        }
        if ($this->glines->countAll() >= $this->maxGlines) {
            return new ManageGlineResult(ManageGlineOutcome::LimitReached, limit: $this->maxGlines);
        }

        $this->glines->save($resolvedMask, $command->actorAccountId, $reason, $expiresAt);
        $this->network->add($resolvedMask, $expiresAt, $reason);
        $this->recordAudit($command, 'GLINE ADD', $resolvedMask, $reason, ['duration' => null === $expiresAt ? 'permanent' : $expiry]);

        return new ManageGlineResult(ManageGlineOutcome::Added, $resolvedMask, expiry: $expiry, reason: $reason);
    }

    private function delete(ManageGline $command): ManageGlineResult
    {
        $item = $command->mask ?? '';
        if ('' === $item) {
            return new ManageGlineResult(ManageGlineOutcome::InvalidRequest);
        }

        $gline = $this->findByItem($item);
        if (null === $gline) {
            return new ManageGlineResult(ManageGlineOutcome::NotFound, mask: $item);
        }

        $this->glines->remove($gline);
        $this->network->remove($gline->mask);
        $this->recordAudit($command, 'GLINE DEL', $gline->mask, null);

        return new ManageGlineResult(ManageGlineOutcome::Deleted, $gline->mask);
    }

    private function list(ManageGline $command): ManageGlineResult
    {
        $pattern = $command->listPattern;
        $glines = null === $pattern || '' === $pattern ? $this->glines->findAll() : $this->glines->findByMaskPattern($pattern);
        if ([] === $glines) {
            return new ManageGlineResult(ManageGlineOutcome::ListEmpty);
        }

        $entries = [];
        foreach ($glines as $gline) {
            $entries[] = new GlineListEntry(
                $gline->mask,
                $gline->reason,
                null === $gline->creatorAccountId ? null : $this->users->findNicknameByAccountId($gline->creatorAccountId),
                $gline->expiresAt,
            );
        }

        return new ManageGlineResult(ManageGlineOutcome::Listed, entries: $entries);
    }

    private function protectedNicknameMatching(string $mask): ?string
    {
        foreach ($this->protectedSubjects->nicknames() as $nickname) {
            $user = $this->users->findByNickname($nickname);
            if (null !== $user && $this->matches($mask, $user)) {
                return $nickname;
            }
        }

        return null;
    }

    private function matches(string $glineMask, GlineUser $user): bool
    {
        $at = strpos(strtolower($glineMask), '@');
        if (false === $at) {
            return false;
        }

        $userPattern = substr(strtolower($glineMask), 0, $at);
        $hostPattern = substr(strtolower($glineMask), $at + 1);

        return fnmatch($userPattern, strtolower($user->ident)) && fnmatch($hostPattern, strtolower($user->hostname));
    }

    private function findByItem(string $item): ?GlineEntry
    {
        if (!ctype_digit($item)) {
            return $this->glines->findByMask($item);
        }

        $index = (int) $item - 1;
        if (0 > $index) {
            return null;
        }

        return $this->glines->findAll()[$index] ?? null;
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private function recordAudit(ManageGline $command, string $operation, string $target, ?string $reason, array $metadata = []): void
    {
        $this->audit->record(new CommandAuditRecord(
            CommandAuditCategory::OperatorAction,
            'operserv',
            $command->actor,
            $operation,
            $command->occurredAt,
            $target,
            $reason,
            'operserv.gline',
            metadata: $metadata,
        ));
    }
}
