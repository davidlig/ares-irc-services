<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Disable;

use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Domain\Entity\MemoSettings;

use function strtolower;

final readonly class DisableMemosHandler implements DisableMemosHandlerInterface
{
    public function __construct(
        private MemoChannelPort $channelPort,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
    ) {}

    public function handle(DisableMemos $command): DisableMemosResult
    {
        if (null !== $command->channelName) {
            return $this->disableChannel($command);
        }

        return $this->disableNick($command);
    }

    private function disableChannel(DisableMemos $command): DisableMemosResult
    {
        /** @var string $channelName */
        $channelName = $command->channelName;
        $channel = $this->channelPort->findChannelByName(strtolower($channelName));
        if (null === $channel) {
            return DisableMemosResult::channelNotRegistered($channelName);
        }

        if (!$this->channelPort->isChannelFounder($channel->id, $command->senderNickId)) {
            return DisableMemosResult::founderOnly($channelName);
        }

        $settings = $this->memoSettingsRepository->findByTargetChannel($channel->id);
        if (null !== $settings) {
            if (!$settings->isEnabled()) {
                return DisableMemosResult::alreadyDisabledChannel($channelName);
            }
            $settings->disable();
        } else {
            $settings = new MemoSettings(null, $channel->id, false);
        }

        $this->memoSettingsRepository->save($settings);

        return DisableMemosResult::disabledChannel($channel->name);
    }

    private function disableNick(DisableMemos $command): DisableMemosResult
    {
        $settings = $this->memoSettingsRepository->findByTargetNick($command->senderNickId);
        if (null !== $settings) {
            if (!$settings->isEnabled()) {
                return DisableMemosResult::alreadyDisabledNick();
            }
            $settings->disable();
        } else {
            $settings = new MemoSettings($command->senderNickId, null, false);
        }

        $this->memoSettingsRepository->save($settings);

        return DisableMemosResult::disabledNick();
    }
}
