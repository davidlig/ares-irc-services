<?php

declare(strict_types=1);

namespace App\ChanServ\Application\UseCase\ManageManualRank;

use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\ValueObject\ChannelRank;
use App\ChanServ\Domain\ValueObject\RankChangeAction;

enum ManualRankOperation: string
{
    case Admin = 'ADMIN';
    case Deadmin = 'DEADMIN';
    case Halfop = 'HALFOP';
    case Dehalfop = 'DEHALFOP';
    case Op = 'OP';
    case Deop = 'DEOP';
    case Voice = 'VOICE';
    case Devoice = 'DEVOICE';

    public function rank(): ChannelRank
    {
        return match ($this) {
            self::Admin, self::Deadmin => ChannelRank::Administrator,
            self::Halfop, self::Dehalfop => ChannelRank::HalfOperator,
            self::Op, self::Deop => ChannelRank::Operator,
            self::Voice, self::Devoice => ChannelRank::Voice,
        };
    }

    public function action(): RankChangeAction
    {
        return match ($this) {
            self::Admin, self::Halfop, self::Op, self::Voice => RankChangeAction::Grant,
            self::Deadmin, self::Dehalfop, self::Deop, self::Devoice => RankChangeAction::Revoke,
        };
    }

    public function requiredLevelKey(): string
    {
        return match ($this) {
            self::Admin, self::Deadmin => ChannelLevel::KEY_ADMINDEADMIN,
            self::Halfop, self::Dehalfop => ChannelLevel::KEY_HALFOPDEHALFOP,
            self::Op, self::Deop => ChannelLevel::KEY_OPDEOP,
            self::Voice, self::Devoice => ChannelLevel::KEY_VOICEDEVOICE,
        };
    }

    public function automaticLevelKey(): string
    {
        return match ($this) {
            self::Admin, self::Deadmin => ChannelLevel::KEY_AUTOADMIN,
            self::Halfop, self::Dehalfop => ChannelLevel::KEY_AUTOHALFOP,
            self::Op, self::Deop => ChannelLevel::KEY_AUTOOP,
            self::Voice, self::Devoice => ChannelLevel::KEY_AUTOVOICE,
        };
    }
}
