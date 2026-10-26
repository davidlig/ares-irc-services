<?php

declare(strict_types=1);

namespace App\ChanServ\Application\Port\Out;

use App\ChanServ\Domain\Entity\ChannelRegistrationAudit;

interface ChannelRegistrationAuditRepository
{
    public function save(ChannelRegistrationAudit $audit): void;
}
