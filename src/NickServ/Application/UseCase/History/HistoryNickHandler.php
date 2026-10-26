<?php

declare(strict_types=1);

namespace App\NickServ\Application\UseCase\History;

use App\NickServ\Application\Port\Out\NickHistoryRepositoryInterface;
use App\NickServ\Application\Port\Out\NickTransactionBoundary;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;
use App\NickServ\Application\Service\NickHistoryService;
use LogicException;

use function ceil;
use function min;

final readonly class HistoryNickHandler implements HistoryNickHandlerInterface
{
    public function __construct(
        private RegisteredNickRepositoryInterface $nickRepository,
        private NickHistoryRepositoryInterface $historyRepository,
        private NickHistoryService $historyService,
        private NickTransactionBoundary $transactionBoundary,
        private int $historyViewLimit = 40,
    ) {}

    public function handle(HistoryNick $command): HistoryNickResult
    {
        $account = $this->nickRepository->findByNick($command->nickname);

        if (null === $account) {
            return HistoryNickResult::notRegistered($command->nickname);
        }

        return match ($command->action) {
            HistoryNickAction::Add => $this->handleAdd($command, $account->getId()),
            HistoryNickAction::Del => $this->handleDel($command, $account->getId()),
            HistoryNickAction::Clear => $this->handleClear($command, $account->getId()),
            HistoryNickAction::View => $this->handleView($command, $account->getId()),
        };
    }

    private function handleAdd(HistoryNick $command, int $nickId): HistoryNickResult
    {
        $this->historyService->recordAction(
            nickId: $nickId,
            action: 'HISTORY_ADD',
            performedBy: $command->operatorNick ?? '',
            performedByNickId: $command->operatorNickId,
            performedByIp: $command->operatorIp ?? '*',
            performedByHost: $command->operatorHost ?? '',
            message: $command->message ?? '',
            performedAt: $command->occurredAt,
        );

        return HistoryNickResult::addSuccess($command->nickname, $command->message ?? '');
    }

    private function handleDel(HistoryNick $command, int $nickId): HistoryNickResult
    {
        if (null === $command->entryId || $command->entryId <= 0) {
            return HistoryNickResult::delInvalidId((string) ($command->entryId ?? ''));
        }

        $history = $this->historyRepository->findById($command->entryId);

        if (null === $history || $history->getNickId() !== $nickId) {
            return HistoryNickResult::delNotFound($command->entryId);
        }

        $this->historyRepository->deleteById($command->entryId);

        return HistoryNickResult::delSuccess($command->nickname, $command->entryId);
    }

    private function handleClear(HistoryNick $command, int $nickId): HistoryNickResult
    {
        $actorNickname = $command->operatorNick;
        if (null === $actorNickname) {
            throw new LogicException('HISTORY CLEAR requires its originating nickname.');
        }

        $count = $this->transactionBoundary->transactional(function () use ($command, $nickId, $actorNickname): int {
            $count = $this->historyRepository->deleteByNickId($nickId);
            $this->historyService->recordWithExplicitMetadata(
                nickId: $nickId,
                action: 'HISTORY_CLEAR',
                performedBy: $actorNickname,
                performedByNickId: null,
                message: 'history.message.history_clear',
                performedAt: $command->occurredAt,
            );

            return $count;
        });

        if (!is_int($count)) {
            throw new LogicException('Nick history transaction must return the deleted count.');
        }

        return HistoryNickResult::clearSuccess($command->nickname, $count);
    }

    private function handleView(HistoryNick $command, int $nickId): HistoryNickResult
    {
        $total = $this->historyRepository->countByNickId($nickId);

        if (0 === $total) {
            return HistoryNickResult::viewNoEntries($command->nickname);
        }

        $page = $command->page < 1 ? 1 : $command->page;
        $limit = $command->showAll ? null : $this->historyViewLimit;
        $offset = $command->showAll ? 0 : ($page - 1) * $this->historyViewLimit;

        $entries = $this->historyRepository->findByNickId($nickId, $limit, $offset);

        $totalPages = $command->showAll ? 1 : (int) ceil($total / $this->historyViewLimit);
        $start = $command->showAll ? 1 : ($page - 1) * $this->historyViewLimit + 1;
        $end = $command->showAll ? $total : min($page * $this->historyViewLimit, $total);

        $views = [];
        foreach ($entries as $entry) {
            $operatorExists = true;
            if (null !== $entry->getPerformedByNickId()) {
                $operatorExists = null !== $this->nickRepository->findById($entry->getPerformedByNickId());
            }

            $views[] = new NickHistoryEntryView(
                id: $entry->getId(),
                performedAt: $entry->getPerformedAt(),
                action: $entry->getAction(),
                performedBy: $entry->getPerformedBy(),
                performedByNickId: $entry->getPerformedByNickId(),
                operatorExists: $operatorExists,
                message: $entry->getMessage(),
                extraData: $entry->getExtraData(),
            );
        }

        return HistoryNickResult::viewSuccess(
            targetNick: $command->nickname,
            entries: $views,
            start: $start,
            end: $end,
            total: $total,
            page: $page,
            totalPages: $totalPages,
            showAll: $command->showAll,
        );
    }
}
