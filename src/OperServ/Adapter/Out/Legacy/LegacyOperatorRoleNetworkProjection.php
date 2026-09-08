<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Legacy;

use App\Application\OperServ\ForcedVhostApplier;
use App\Application\OperServ\IrcopModeApplier;
use App\Application\OperServ\IrcopOperclassApplier;
use App\Application\Port\ActiveConnectionHolderInterface;
use App\Application\Port\EventBusInterface;
use App\Application\Port\OperclassServiceActionsInterface;
use App\Domain\OperServ\Event\OperRoleForcedVhostChangedEvent;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\OperServ\Application\Port\Out\OperatorRoleNetworkProjection;

final readonly class LegacyOperatorRoleNetworkProjection implements OperatorRoleNetworkProjection
{
    public function __construct(private IrcopModeApplier $modes, private ForcedVhostApplier $vhost, private IrcopOperclassApplier $operclass, private ActiveConnectionHolderInterface $connection, private EventBusInterface $events) {}

    /**
     * @param list<string> $oldModes
     * @param list<string> $newModes
     */
    public function refreshModes(int $roleId, array $oldModes, array $newModes): void
    {
        $this->modes->updateModesForRole($roleId, $oldModes, $newModes);
    }

    public function refreshVhost(int $roleId, ?string $pattern): void
    {
        $this->vhost->updateVhostForRole($roleId, $pattern);
        $this->events->dispatch(new OperRoleForcedVhostChangedEvent($roleId, $pattern));
    }

    public function refreshOperclass(int $roleId, ?string $operclass): void
    {
        $this->operclass->updateForRole($roleId, $operclass);
    }

    public function supportsOperclass(): bool
    {
        return $this->actions() instanceof OperclassServiceActionsInterface;
    }

    public function availableOperclasses(): ?array
    {
        $actions = $this->actions();
        if (!$actions instanceof OperclassServiceActionsInterface) {
            return null;
        }

        return $actions->getAvailableOperclasses();
    }

    private function actions(): ?ProtocolServiceActionsInterface
    {
        return $this->connection->getProtocolModule()?->getServiceActions();
    }
}
