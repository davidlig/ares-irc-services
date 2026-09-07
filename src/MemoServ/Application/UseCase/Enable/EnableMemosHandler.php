<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Enable;

use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Domain\Entity\MemoSettings;

use function strtolower;

final readonly class EnableMemosHandler implements EnableMemosHandlerInterface
{
    public function __construct(
        private MemoChannelPort $channelPort,
        private MemoSettingsRepositoryInterface $memoSettingsRepository,
    ) {}

    public function handle(EnableMemos $command): EnableMemosResult
    {
        if (null !== $command->channelName) {
            return $this->enableChannel($command);
        }

        return $this->enableNick($command);
    }

    private function enableChannel(EnableMemos $command): EnableMemosResult
    {
        /** @var string $channelName */
        $channelName = $command->channelName;
        $channel = $this->channelPort->findChannelByName(strtolower($channelName));
        if (null === $channel) {
            return EnableMemosResult::channelNotRegistered($channelName);
        }

        if (!$this->channelPort->isChannelFounder($channel->id, $command->senderNickId)) {
            return EnableMemosResult::founderOnly($channelName);
        }

        $settings = $this->memoSettingsRepository->findByTargetChannel($channel->id);
        if (null !== $settings) {
            if ($settings->isEnabled()) {
                return EnableMemosResult::alreadyEnabledChannel($channelName);
            }
            $settings->enable();
        } else {
            $settings = new MemoSettings(null, $channel->id, true);
        }

        $this->memoSettingsRepository->save($settings);

        return EnableMemosResult::enabledChannel($channel->name);
    }

    private function enableNick(EnableMemos $command): EnableMemosResult
    {
        $settings = $this->memoSettingsRepository->findByTargetNick($command->senderNickId);
        if (null !== $settings) {
            if ($settings->isEnabled()) {
                return EnableMemosResult::alreadyEnabledNick();
            }
            $settings->enable();
        } else {
            $settings = new MemoSettings($command->senderNickId, null, true);
        }

        $this->memoSettingsRepository->save($settings);

        return EnableMemosResult::enabledNick();
    }
}
