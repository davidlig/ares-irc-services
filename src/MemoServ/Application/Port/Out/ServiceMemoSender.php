<?php

declare(strict_types=1);

namespace App\MemoServ\Application\Port\Out;

interface ServiceMemoSender
{
    public function getSenderName(): string;
}
