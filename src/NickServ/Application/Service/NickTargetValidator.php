<?php

declare(strict_types=1);

namespace App\NickServ\Application\Service;

use App\Application\OperServ\RootUserRegistry;
use App\Application\Shared\ServiceUidRegistry;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\NickServ\Application\Port\Out\RegisteredNickRepositoryInterface;

use function strtolower;

readonly class NickTargetValidator
{
    public function __construct(
        private RootUserRegistry $rootRegistry,
        private OperIrcopRepositoryInterface $ircopRepository,
        private ServiceUidRegistry $serviceUidRegistry,
        private RegisteredNickRepositoryInterface $nickRepository,
    ) {}

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
        $isIrcop = null !== $account && null !== $this->ircopRepository->findByNickId($account->getId());

        return $isIrcop
            ? NickProtectabilityResult::ircop($nickname)
            : NickProtectabilityResult::allowed($nickname, $account);
    }
}
