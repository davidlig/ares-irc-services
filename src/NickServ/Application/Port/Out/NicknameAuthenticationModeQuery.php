<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\Out;

use App\NickServ\Application\Model\NicknameAuthenticationMode;

interface NicknameAuthenticationModeQuery
{
    public function current(): NicknameAuthenticationMode;
}
