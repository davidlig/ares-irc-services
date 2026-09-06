<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Security;

use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use App\Domain\OperServ\ValueObject\ForcedVhost;
use App\NickServ\Application\Port\Out\ForcedVhostCheckerInterface;

final readonly class OperIrcopForcedVhostChecker implements ForcedVhostCheckerInterface
{
    public function __construct(private OperIrcopRepositoryInterface $ircopRepository) {}

    public function hasForcedVhost(int $nickId): bool
    {
        $ircop = $this->ircopRepository->findByNickId($nickId);
        if (null === $ircop) {
            return false;
        }

        $pattern = $ircop->getRole()->getForcedVhostPattern();

        return null !== $pattern && '' !== $pattern && ForcedVhost::isValidPattern($pattern);
    }
}
