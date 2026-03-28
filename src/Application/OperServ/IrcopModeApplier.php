<?php

declare(strict_types=1);

namespace App\Application\OperServ;

use App\Application\NickServ\IdentifiedSessionRegistry;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\NetworkUserLookupPort;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperIrcopRepositoryInterface;
use Psr\Log\LoggerInterface;

use function array_diff;
use function array_intersect;

/**
 * Applies or removes IRCOP user modes for a role.
 * Used when an IRCOP is added/removed/changed, or when role modes are changed.
 */
final readonly class IrcopModeApplier
{
    public function __construct(
        private IdentifiedSessionRegistry $identifiedRegistry,
        private ActiveConnectionHolderInterface $connectionHolder,
        private OperIrcopRepositoryInterface $ircopRepository,
        private RegisteredNickRepositoryInterface $nickRepository,
        private NetworkUserLookupPort $userLookup,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Apply modes for a role to a user identified by their registered nickname.
     * Only applies modes the user doesn't already have.
     * Returns true if modes were applied, false if user is not connected or no protocol module.
     */
    public function applyModesForNick(string $registeredNick, OperRole $role): bool
    {
        $modes = $role->getUserModes();
        if (empty($modes)) {
            $this->logger->debug('IrcopModeApplier: no modes for role', ['role' => $role->getName(), 'nick' => $registeredNick]);

            return false;
        }

        $uid = $this->identifiedRegistry->findUidByNick($registeredNick);
        if (null === $uid) {
            $this->logger->debug('IrcopModeApplier: user not identified', ['nick' => $registeredNick]);

            return false;
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->debug('IrcopModeApplier: no protocol module');

            return false;
        }

        $user = $this->userLookup->findByUid($uid);
        if (null === $user) {
            $this->logger->debug('IrcopModeApplier: user not found in network', ['uid' => $uid]);

            return false;
        }

        $currentModes = $this->parseModes($user->modes);
        $toApply = array_diff($modes, $currentModes);

        if (empty($toApply)) {
            $this->logger->debug('IrcopModeApplier: user already has all modes', ['nick' => $registeredNick, 'uid' => $uid, 'modes' => $modes]);

            return true;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        $serviceActions = $module->getServiceActions();

        $modesStr = '+' . implode('', $toApply);
        $this->logger->info('IrcopModeApplier: applying modes', ['nick' => $registeredNick, 'uid' => $uid, 'modes' => $modesStr]);
        $serviceActions->setUserMode($serverSid, $uid, $modesStr);

        $this->userLookup->applyModeChange($uid, $modesStr);

        return true;
    }

    /**
     * Remove modes for a role from a user identified by their registered nickname.
     * Only removes modes the user currently has.
     * Returns true if modes were removed, false if user is not connected or no protocol module.
     */
    public function removeModesForNick(string $registeredNick, OperRole $role): bool
    {
        $modes = $role->getUserModes();
        if (empty($modes)) {
            return false;
        }

        $uid = $this->identifiedRegistry->findUidByNick($registeredNick);
        if (null === $uid) {
            return false;
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            return false;
        }

        $user = $this->userLookup->findByUid($uid);
        if (null === $user) {
            return false;
        }

        $currentModes = $this->parseModes($user->modes);
        $toRemove = array_intersect($modes, $currentModes);

        if (empty($toRemove)) {
            $this->logger->debug('IrcopModeApplier: user does not have any of the modes to remove', ['nick' => $registeredNick, 'uid' => $uid, 'modes' => $modes]);

            return true;
        }

        $serverSid = $this->connectionHolder->getServerSid();
        $serviceActions = $module->getServiceActions();

        $modesStr = '-' . implode('', $toRemove);
        $this->logger->info('IrcopModeApplier: removing modes', ['nick' => $registeredNick, 'uid' => $uid, 'modes' => $modesStr]);
        $serviceActions->setUserMode($serverSid, $uid, $modesStr);

        $this->userLookup->applyModeChange($uid, $modesStr);

        return true;
    }

    /**
     * Update modes for all identified users with a specific role.
     * Only sends mode changes for modes that differ between old and new.
     */
    public function updateModesForRole(int $roleId, array $oldModes, array $newModes): void
    {
        $toRemove = array_diff($oldModes, $newModes);
        $toAdd = array_diff($newModes, $oldModes);

        if (empty($toRemove) && empty($toAdd)) {
            return;
        }

        $module = $this->connectionHolder->getProtocolModule();
        if (null === $module) {
            $this->logger->debug('IrcopModeApplier: no protocol module for role update');

            return;
        }

        $ircops = $this->ircopRepository->findByRoleId($roleId);

        foreach ($ircops as $ircop) {
            $nick = $this->nickRepository->findById($ircop->getNickId());
            if (null === $nick) {
                continue;
            }

            $uid = $this->identifiedRegistry->findUidByNick($nick->getNickname());
            if (null === $uid) {
                continue;
            }

            $user = $this->userLookup->findByUid($uid);
            if (null === $user) {
                continue;
            }

            $currentModes = $this->parseModes($user->modes);

            $serverSid = $this->connectionHolder->getServerSid();
            $serviceActions = $module->getServiceActions();

            $modesToRemove = array_intersect($toRemove, $currentModes);
            if (!empty($modesToRemove)) {
                $modesStr = '-' . implode('', $modesToRemove);
                $this->logger->info('IrcopModeApplier: removing modes for role change', [
                    'nick' => $nick->getNickname(),
                    'uid' => $uid,
                    'modes' => $modesStr,
                ]);
                $serviceActions->setUserMode($serverSid, $uid, $modesStr);
                $this->userLookup->applyModeChange($uid, $modesStr);
            }

            $modesToAdd = array_diff($toAdd, $currentModes);
            if (!empty($modesToAdd)) {
                $modesStr = '+' . implode('', $modesToAdd);
                $this->logger->info('IrcopModeApplier: applying modes for role change', [
                    'nick' => $nick->getNickname(),
                    'uid' => $uid,
                    'modes' => $modesStr,
                ]);
                $serviceActions->setUserMode($serverSid, $uid, $modesStr);
                $this->userLookup->applyModeChange($uid, $modesStr);
            }
        }
    }

    /**
     * Parse mode string (e.g. "+ioqrtwxH") into an array of individual modes.
     */
    private function parseModes(string $modesStr): array
    {
        $modes = ltrim($modesStr, '+');

        return str_split($modes);
    }
}
