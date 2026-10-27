<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\Badmail;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\OperServ\Application\Port\In\CommandAuditRecorder;
use App\OperServ\Application\Port\Out\BadmailRepository;
use App\OperServ\Application\Security\OperServPermission;
use App\OperServ\Domain\Entity\BadmailEntry;
use App\OperServ\Domain\ValueObject\EmailDomain;
use InvalidArgumentException;

use function ceil;
use function in_array;
use function max;
use function trim;

final readonly class ManageBadmailHandler
{
    private int $pageSize;

    public function __construct(private CommandAuditRecorder $audit, private BadmailRepository $repository, int $pageSize)
    {
        $this->pageSize = max(1, $pageSize);
    }

    public function handle(ManageBadmail $input): BadmailResult
    {
        if (!$input->authorized) {
            return new BadmailResult(BadmailOutcome::Denied);
        }

        $result = match ($input->action) {
            BadmailAction::Add => $this->add($input),
            BadmailAction::Delete => $this->delete($input->id),
            BadmailAction::Clear => new BadmailResult(BadmailOutcome::Cleared, total: $this->repository->clear()),
            BadmailAction::List => $this->list($input->pattern, $input->page),
        };
        if (in_array($result->outcome, [BadmailOutcome::Added, BadmailOutcome::Deleted, BadmailOutcome::Cleared, BadmailOutcome::Listed], true)) {
            $this->audit->record(new CommandAuditRecord(
                CommandAuditCategory::OperatorAction,
                'operserv',
                $input->actor,
                match ($input->action) {
                    BadmailAction::Add => 'BADMAIL ADD',
                    BadmailAction::Delete, BadmailAction::Clear => 'BADMAIL DEL',
                    BadmailAction::List => 'BADMAIL LIST',
                },
                $input->occurredAt,
                $result->domain ?? (null !== $input->id ? (string) $input->id : (BadmailAction::List === $input->action ? $input->pattern : 'ALL')),
                BadmailAction::Add === $input->action ? trim($input->reason) : null,
                OperServPermission::BADMAIL,
                metadata: ['action' => $input->action->name, 'page' => $result->page, 'total' => $result->total],
            ));
        }

        return $result;
    }

    private function add(ManageBadmail $input): BadmailResult
    {
        try {
            $domain = new EmailDomain($input->domain);
        } catch (InvalidArgumentException) {
            return new BadmailResult(BadmailOutcome::InvalidDomain);
        }
        try {
            $entry = new BadmailEntry($domain->value, $input->reason);
        } catch (InvalidArgumentException) {
            return new BadmailResult(BadmailOutcome::InvalidReason);
        }

        return new BadmailResult(
            $this->repository->add($entry) ? BadmailOutcome::Added : BadmailOutcome::Duplicate,
            domain: $domain->value,
        );
    }

    private function delete(?int $id): BadmailResult
    {
        if (null === $id || 1 > $id) {
            return new BadmailResult(BadmailOutcome::InvalidId);
        }

        return new BadmailResult($this->repository->delete($id) ? BadmailOutcome::Deleted : BadmailOutcome::NotFound);
    }

    private function list(string $pattern, int $page): BadmailResult
    {
        if ('' === trim($pattern)) {
            return new BadmailResult(BadmailOutcome::InvalidPattern);
        }
        $page = max(1, $page);
        $total = $this->repository->countMatching($pattern);
        $entries = [];
        if ($page <= (int) ceil($total / $this->pageSize)) {
            $entries = $this->repository->page($pattern, ($page - 1) * $this->pageSize, $this->pageSize);
        }

        return new BadmailResult(BadmailOutcome::Listed, entries: $entries, total: $total, page: $page, pageSize: $this->pageSize);
    }
}
