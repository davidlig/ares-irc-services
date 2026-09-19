<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\NickServ\Application\Port\Out\NickProtectionExemption;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;

readonly class NickTargetValidator
{
    public function __construct(
        private NickProtectionExemption $protectionExemption,
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {}

    public function validate(string $nickname): NickProtectabilityResult
    {
        if ($this->protectionExemption->isRootNickname($nickname)) {
            return NickProtectabilityResult::root($nickname);
        }

        if ($this->protectionExemption->isServiceNickname($nickname)) {
            return NickProtectabilityResult::service($nickname);
        }

        $account = $this->nickRepository->findByNick($nickname);
        $isIrcop = null !== $account && $this->protectionExemption->isIrcopNickId($account->getId());

        return $isIrcop
            ? NickProtectabilityResult::ircop($nickname)
            : NickProtectabilityResult::allowed($nickname, $account);
    }
}
