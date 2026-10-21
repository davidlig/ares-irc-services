<?php

declare(strict_types=1);

namespace App\NickServ\Application\Port\In;

interface RescueTokenValidator
{
    public function validate(RescueTokenValidation $input): RescueTokenValidationResult;
}
