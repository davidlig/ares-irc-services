<?php

declare(strict_types=1);

namespace App\ChanServ\Domain\ValueObject;

enum ChannelLevel: string
{
    case AutoAdmin = 'AUTOADMIN';
    case AutoOperator = 'AUTOOP';
    case AutoHalfOperator = 'AUTOHALFOP';
    case AutoVoice = 'AUTOVOICE';
    case Set = 'SET';
    case AdminDeadmin = 'ADMINDEADMIN';
    case OpDeop = 'OPDEOP';
    case HalfopDehalfop = 'HALFOPDEHALFOP';
    case VoiceDevoice = 'VOICEDEVOICE';
    case Invite = 'INVITE';
    case AccessList = 'ACCESSLIST';
    case AccessChange = 'ACCESSCHANGE';
    case MemoRead = 'MEMOREAD';
    case MemoChange = 'MEMOCHANGE';
    case Akick = 'AKICK';
    case NoJoin = 'NOJOIN';

    public function defaultValue(): int
    {
        return match ($this) {
            self::AutoAdmin, self::AdminDeadmin, self::AccessList => 400,
            self::AutoOperator, self::OpDeop, self::MemoChange => 300,
            self::AutoHalfOperator, self::HalfopDehalfop, self::Invite, self::MemoRead => 200,
            self::AutoVoice, self::VoiceDevoice => 100,
            self::Set, self::AccessChange => 499,
            self::Akick => 450,
            self::NoJoin => -1,
        };
    }
}
