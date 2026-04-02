<?php

declare(strict_types=1);

namespace App\Application\Command;

interface AuditableCommandInterface
{
    public function getAuditData(object $context): ?IrcopAuditData;
}
