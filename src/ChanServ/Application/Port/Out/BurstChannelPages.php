<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Application\Model\BurstChannelPage;

interface BurstChannelPages
{
    /** @return iterable<BurstChannelPage> */
    public function pages(): iterable;
}
