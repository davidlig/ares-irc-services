<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRank;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRankHandlerInterface;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRankOutcome;
use App\ChanServ\Application\UseCase\ManageManualRank\ManualRankOperation;
use App\ChanServ\Domain\ValueObject\RankChangeAction;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function strtolower;

trait ManualRankCommandPresentation
{
    public function __construct(
        private readonly ManageManualRankHandlerInterface $manualRankHandler,
    ) {}

    public function execute(ChanServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return;
        }

        if (!$this->validateArguments($context)) {
            return;
        }

        $targetNickname = $context->args[1];

        $operation = ManualRankOperation::from($this->getName());
        $result = $this->manualRankHandler->handle(new ManageManualRank(
            channelName: $channelName,
            targetNickname: $targetNickname,
            actorAccountId: $context->senderAccount?->id,
            founderOverride: $context->isLevelFounder,
            operation: $operation,
        ));

        switch ($result->outcome) {
            case ManageManualRankOutcome::NotIdentified:
                $context->reply('presentation.error.not_identified', ['%identify%' => new Fragment($context->trans('presentation.identify'), Component::Command)], Role::Error);
                break;
            case ManageManualRankOutcome::RankNotSupported:
                $context->reply($this->rankBase($operation) . '.not_supported', role: Role::Error);
                break;
            case ManageManualRankOutcome::TargetNickNotRegistered:
                $context->reply('presentation.error.nick_not_registered', ['%nickname%' => new Fragment((string) $targetNickname, Component::Emphasis)], Role::Error);
                break;
            case ManageManualRankOutcome::TargetNotOnChannel:
                $context->reply($this->rankBase($operation) . '.user_not_on_channel', ['%nickname%' => new Fragment($targetNickname, Component::Emphasis)], Role::Error);
                break;
            case ManageManualRankOutcome::SecureLevelRequired:
                $context->reply('secure.requires_min_level', [
                    '%nickname%' => new Fragment($targetNickname, Component::Emphasis),
                    '%level%' => new Fragment((string) $result->requiredLevel, Component::Emphasis),
                    '%mode%' => new Fragment($this->displayMode($operation), Component::Emphasis),
                ], Role::Error);
                break;
            case ManageManualRankOutcome::TargetAccessTooHigh:
                $context->reply('presentation.error.insufficient_access', [
                    '%operation%' => new Fragment($operation->value, Component::Emphasis),
                    '%channel%' => new Fragment($channelName, Component::Emphasis),
                ], Role::Error);
                break;
            case ManageManualRankOutcome::Applied:
                $context->getNotifier()->sendNoticeToChannel($channelName, $context->renderReply(
                    Role::Information,
                    $this->rankBase($operation) . '.notice_grant',
                    [
                        '%from%' => new Fragment($context->sender->nick, Component::Actor),
                        '%to%' => new Fragment($targetNickname, Component::Target),
                        '%mode%' => new Fragment($this->displayMode($operation), Component::ModeChange),
                    ],
                ));
                $context->reply(strtolower($operation->value) . '.done', ['%nickname%' => new Fragment($targetNickname, Component::Emphasis)], Role::Success);
                break;
        }
    }

    private function rankBase(ManualRankOperation $operation): string
    {
        return match ($operation) {
            ManualRankOperation::Admin, ManualRankOperation::Deadmin => 'admin',
            ManualRankOperation::Halfop, ManualRankOperation::Dehalfop => 'halfop',
            ManualRankOperation::Op, ManualRankOperation::Deop => 'op',
            ManualRankOperation::Voice, ManualRankOperation::Devoice => 'voice',
        };
    }

    private function displayMode(ManualRankOperation $operation): string
    {
        $sign = RankChangeAction::Grant === $operation->action() ? '+' : '-';
        $letter = match ($operation) {
            ManualRankOperation::Admin, ManualRankOperation::Deadmin => 'a',
            ManualRankOperation::Halfop, ManualRankOperation::Dehalfop => 'h',
            ManualRankOperation::Op, ManualRankOperation::Deop => 'o',
            ManualRankOperation::Voice, ManualRankOperation::Devoice => 'v',
        };

        return $sign . $letter;
    }
}
