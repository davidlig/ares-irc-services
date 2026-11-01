<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageHistory;

use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanTransactionBoundary;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelHistoryService;
use UnexpectedValueException;

use function ceil;
use function is_int;
use function min;
use function strtolower;

final readonly class ManageChannelHistoryHandler implements ManageChannelHistoryHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelHistoryRepositoryInterface $historyRepository,
        private ChannelHistoryService $historyService,
        private ChanUserAccountPort $accountPort,
        private ChanTransactionBoundary $transactions,
        private int $historyViewLimit = 40,
    ) {}

    public function handle(ManageChannelHistory $command): ManageChannelHistoryResult
    {
        $channel = $this->channelRepository->findByChannelName(strtolower($command->channelName));
        if (null === $channel) {
            return new ManageChannelHistoryResult(ManageChannelHistoryOutcome::ChannelNotRegistered);
        }

        return match ($command->action) {
            ChannelHistoryAction::Add => $this->add($command, $channel->getId()),
            ChannelHistoryAction::Delete => $this->delete($command, $channel->getId()),
            ChannelHistoryAction::View => $this->view($command, $channel->getId()),
            ChannelHistoryAction::Clear => $this->clear($command, $channel->getId()),
        };
    }

    private function add(ManageChannelHistory $command, int $channelId): ManageChannelHistoryResult
    {
        $this->historyService->recordAction(
            channelId: $channelId,
            action: 'HISTORY_ADD',
            performedBy: $command->actorNickname,
            performedByNickId: $command->actorAccountId,
            performedByIp: $command->actorIp,
            performedByHost: $command->actorHost,
            performedAt: $command->occurredAt,
            message: $command->message ?? '',
        );

        return new ManageChannelHistoryResult(ManageChannelHistoryOutcome::Added);
    }

    private function delete(ManageChannelHistory $command, int $channelId): ManageChannelHistoryResult
    {
        $entryId = $command->entryId ?? 0;
        $entry = $this->historyRepository->findById($entryId);
        if (null === $entry || $entry->getChannelId() !== $channelId) {
            return new ManageChannelHistoryResult(ManageChannelHistoryOutcome::EntryNotFound, affectedEntryId: $entryId);
        }

        $this->historyRepository->deleteById($entryId);

        return new ManageChannelHistoryResult(ManageChannelHistoryOutcome::Deleted, affectedEntryId: $entryId);
    }

    private function view(ManageChannelHistory $command, int $channelId): ManageChannelHistoryResult
    {
        $total = $this->historyRepository->countByChannelId($channelId);
        if (0 === $total) {
            return new ManageChannelHistoryResult(ManageChannelHistoryOutcome::NoEntries);
        }

        $page = $command->page < 1 ? 1 : $command->page;
        $totalPages = $command->showAll ? 1 : (int) ceil($total / $this->historyViewLimit);
        $entries = [];

        if (!$command->showAll && $page > $totalPages) {
            $start = $total + 1;
            $end = $total;
        } else {
            $limit = $command->showAll ? null : $this->historyViewLimit;
            $offset = $command->showAll ? 0 : ($page - 1) * $this->historyViewLimit;

            foreach ($this->historyRepository->findByChannelId($channelId, $limit, $offset) as $entry) {
                $operatorMissing = null !== $entry->getPerformedByNickId()
                    && null === $this->accountPort->findAccountById($entry->getPerformedByNickId());
                $entries[] = new ChannelHistoryEntryView(
                    $entry->getId(),
                    $entry->getAction(),
                    $entry->getPerformedBy(),
                    $operatorMissing,
                    $entry->getPerformedAt(),
                    $entry->getMessage(),
                    $entry->getExtraData(),
                );
            }

            $start = $command->showAll ? 1 : ($page - 1) * $this->historyViewLimit + 1;
            $end = $command->showAll ? $total : min($page * $this->historyViewLimit, $total);
        }

        return new ManageChannelHistoryResult(
            ManageChannelHistoryOutcome::Viewed,
            entries: $entries,
            total: $total,
            start: $start,
            end: $end,
            totalPages: $totalPages,
            page: $page,
        );
    }

    private function clear(ManageChannelHistory $command, int $channelId): ManageChannelHistoryResult
    {
        $count = $this->transactions->transactional(function () use ($command, $channelId): int {
            $count = $this->historyRepository->deleteByChannelId($channelId);
            $this->historyService->recordWithExplicitMetadata(
                channelId: $channelId,
                action: 'HISTORY_CLEAR',
                performedBy: $command->actorNickname,
                performedByNickId: null,
                performedAt: $command->occurredAt,
                message: 'history.message.history_clear',
            );

            return $count;
        });
        if (!is_int($count)) {
            throw new UnexpectedValueException('Channel history transaction must return the deleted row count.');
        }

        return new ManageChannelHistoryResult(ManageChannelHistoryOutcome::Cleared, total: $count);
    }
}
