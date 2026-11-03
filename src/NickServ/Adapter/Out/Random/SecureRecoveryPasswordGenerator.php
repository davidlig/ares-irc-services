<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Random;

use App\NickServ\Application\Port\Out\RecoveryPasswordGenerator;
use App\NickServ\Application\Service\PasswordPolicy;

use function max;
use function random_int;
use function strlen;

final readonly class SecureRecoveryPasswordGenerator implements RecoveryPasswordGenerator
{
    public function __construct(private PasswordPolicy $passwordPolicy = new PasswordPolicy()) {}

    public function generate(): string
    {
        $password = '';
        foreach ($this->passwordPolicy->requiredCharacterSets() as $characters) {
            $password .= $characters[random_int(0, strlen($characters) - 1)];
        }
        $alphabet = PasswordPolicy::LOWERCASE . PasswordPolicy::UPPERCASE . PasswordPolicy::DIGITS . PasswordPolicy::SYMBOLS;
        $length = max(12, $this->passwordPolicy->minimumLength);
        while (strlen($password) < $length) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        // Fisher-Yates removes predictable positions for the guaranteed categories.
        for ($i = strlen($password) - 1; 0 < $i; --$i) {
            $j = random_int(0, $i);
            [$password[$i], $password[$j]] = [$password[$j], $password[$i]];
        }

        return $password;
    }
}
