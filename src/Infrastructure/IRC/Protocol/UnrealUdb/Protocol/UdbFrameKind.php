<?php

declare(strict_types=1);

namespace App\Infrastructure\IRC\Protocol\UnrealUdb\Protocol;

enum UdbFrameKind: string
{
    case Hel = 'HEL';
    case HelAck = 'HEL_ACK';
    case Inf = 'INF';
    case Res = 'RES';
    case Begin = 'BEGIN';
    case Put = 'PUT';
    case End = 'END';
    case Ack = 'ACK';
    case Err = 'ERR';
    case Ins = 'INS';
    case Del = 'DEL';
    case Drp = 'DRP';
    case Opt = 'OPT';
    case OclgBegin = 'OCLG_BEGIN';
    case OclgItem = 'OCLG_ITEM';
    case OclgEnd = 'OCLG_END';
}
