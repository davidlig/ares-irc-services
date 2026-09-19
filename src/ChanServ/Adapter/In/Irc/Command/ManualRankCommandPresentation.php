<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRank;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRankHandlerInterface;
use App\ChanServ\Application\UseCase\ManageManualRank\ManageManualRankOutcome;
use App\ChanServ\Application\UseCase\ManageManualRank\ManualRankOperation;
use App\ChanServ\Domain\ValueObject\RankChangeAction;

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
            $context->reply('error.invalid_channel');

            return;
        }

        $targetNickname = $context->args[1] ?? '';
        if ('' === $targetNickname) {
            $context->reply('error.syntax', ['syntax' => $context->trans($this->getSyntaxKey())]);

            return;
        }

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
                $context->reply('error.not_identified');
                break;
            case ManageManualRankOutcome::RankNotSupported:
                $context->reply($this->rankBase($operation) . '.not_supported');
                break;
            case ManageManualRankOutcome::TargetNickNotRegistered:
                $context->reply('error.nick_not_registered', ['%nickname%' => $targetNickname]);
                break;
            case ManageManualRankOutcome::TargetNotOnChannel:
                $context->reply($this->rankBase($operation) . '.user_not_on_channel', ['%nickname%' => $targetNickname]);
                break;
            case ManageManualRankOutcome::SecureLevelRequired:
                $context->reply('secure.requires_min_level', [
                    '%nickname%' => $targetNickname,
                    '%level%' => (string) $result->requiredLevel,
                    '%mode%' => $this->displayMode($operation),
                ]);
                break;
            case ManageManualRankOutcome::TargetAccessTooHigh:
                $context->reply('error.insufficient_access', [
                    '%operation%' => $operation->value,
                    '%channel%' => $channelName,
                ]);
                break;
            case ManageManualRankOutcome::Applied:
                $context->getNotifier()->sendNoticeToChannel($channelName, $context->trans(
                    $this->rankBase($operation) . '.notice_grant',
                    [
                        '%from%' => $context->sender->nick,
                        '%to%' => $targetNickname,
                        '%mode%' => $this->displayMode($operation),
                    ],
                ));
                $context->reply(strtolower($operation->value) . '.done', ['%nickname%' => $targetNickname]);
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
