<?php

declare(strict_types=1);

namespace App\MemoServ\Application\UseCase\Ignore;

enum IgnoreMemoAction: string
{
    case Add = 'ADD';
    case Del = 'DEL';
    case List = 'LIST';
}
