<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageHistory;

use App\ChanServ\Application\Port\Out\ChannelHistoryRepositoryInterface;
use App\ChanServ\Application\Port\Out\ChanUserAccountPort;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\Service\ChannelHistoryService;

use function ceil;
use function min;
use function strtolower;

final readonly class ManageChannelHistoryHandler implements ManageChannelHistoryHandlerInterface
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channelRepository,
        private ChannelHistoryRepositoryInterface $historyRepository,
        private ChannelHistoryService $historyService,
        private ChanUserAccountPort $accountPort,
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
            ChannelHistoryAction::Clear => $this->clear($channel->getId()),
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
        $limit = $command->showAll ? null : $this->historyViewLimit;
        $offset = $command->showAll ? 0 : ($page - 1) * $this->historyViewLimit;
        $entries = [];
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

        return new ManageChannelHistoryResult(
            ManageChannelHistoryOutcome::Viewed,
            entries: $entries,
            total: $total,
            start: $command->showAll ? 1 : ($page - 1) * $this->historyViewLimit + 1,
            end: $command->showAll ? $total : min($page * $this->historyViewLimit, $total),
            totalPages: $command->showAll ? 1 : (int) ceil($total / $this->historyViewLimit),
            page: $page,
        );
    }

    private function clear(int $channelId): ManageChannelHistoryResult
    {
        $count = $this->historyRepository->deleteByChannelId($channelId);

        return new ManageChannelHistoryResult(ManageChannelHistoryOutcome::Cleared, total: $count);
    }
}
