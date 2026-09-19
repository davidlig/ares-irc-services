<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\ForbidVhost;

use App\NickServ\Application\Port\Out\Clock;
use App\NickServ\Application\Port\Out\ForbiddenVhostRepositoryInterface;
use App\NickServ\Application\Service\ForbiddenPatternValidator;
use App\NickServ\Application\Service\ForbiddenVhostService;

final readonly class ManageForbiddenVhostHandler
{
    public function __construct(
        private ForbiddenVhostRepositoryInterface $repository,
        private ForbiddenVhostService $service,
        private ForbiddenPatternValidator $validator,
        private Clock $clock,
    ) {}

    public function handle(ManageForbiddenVhost $input): ManageForbiddenVhostResult
    {
        return match ($input->action) {
            ForbiddenVhostAction::Add => $this->add((string) $input->pattern, $input->creatorNickId),
            ForbiddenVhostAction::Delete => $this->delete((string) $input->pattern),
            ForbiddenVhostAction::List => $this->list(),
        };
    }

    private function add(string $pattern, ?int $creatorNickId): ManageForbiddenVhostResult
    {
        if (!$this->validator->isValid($pattern)) {
            return new ManageForbiddenVhostResult(ManageForbiddenVhostOutcome::InvalidPattern, $pattern);
        }

        if (null !== $this->repository->findByPattern($pattern)) {
            return new ManageForbiddenVhostResult(ManageForbiddenVhostOutcome::AlreadyExists, $pattern);
        }

        $this->service->forbid($pattern, $creatorNickId, $this->clock->now());

        return new ManageForbiddenVhostResult(ManageForbiddenVhostOutcome::Added, $pattern);
    }

    private function delete(string $pattern): ManageForbiddenVhostResult
    {
        if (!$this->service->unforbid($pattern)) {
            return new ManageForbiddenVhostResult(ManageForbiddenVhostOutcome::NotFound, $pattern);
        }

        return new ManageForbiddenVhostResult(ManageForbiddenVhostOutcome::Deleted, $pattern);
    }

    private function list(): ManageForbiddenVhostResult
    {
        $entries = array_values(array_map(
            static fn ($forbidden): ForbiddenVhostView => new ForbiddenVhostView(
                $forbidden->getPattern(),
                $forbidden->getCreatedByNickId(),
                $forbidden->getCreatedAt(),
            ),
            $this->service->getAll(),
        ));

        if ([] === $entries) {
            return new ManageForbiddenVhostResult(ManageForbiddenVhostOutcome::Empty);
        }

        return new ManageForbiddenVhostResult(ManageForbiddenVhostOutcome::Listed, entries: $entries);
    }
}
