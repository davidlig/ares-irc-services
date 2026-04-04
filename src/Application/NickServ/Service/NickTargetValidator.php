<?php

declare(strict_types=1);

namespace App\Application\NickServ\Service;

use App\Application\ApplicationPort\ServiceUidRegistry;
use App\Application\OperServ\RootUserRegistry;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;

use function strtolower;

readonly class NickTargetValidator
{
    public function __construct(
        private RootUserRegistry $rootRegistry,
        private OperIrcopRepositoryInterface $ircopRepository,
        private ServiceUidRegistry $serviceUidRegistry,
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {
    }

    public function validate(string $nickname): NickProtectabilityResult
    {
        $nicknameLower = strtolower($nickname);

        if ($this->rootRegistry->isRoot($nicknameLower)) {
            return NickProtectabilityResult::root($nickname);
        }

        if (null !== $this->serviceUidRegistry->getUidByNickname($nickname)) {
            return NickProtectabilityResult::service($nickname);
        }

        $account = $this->nickRepository->findByNick($nickname);

        if (null !== $account) {
            $ircop = $this->ircopRepository->findByNickId($account->getId());

            if (null !== $ircop) {
                return NickProtectabilityResult::ircop($nickname);
            }
        }

        return NickProtectabilityResult::allowed($nickname, $account);
    }
}
