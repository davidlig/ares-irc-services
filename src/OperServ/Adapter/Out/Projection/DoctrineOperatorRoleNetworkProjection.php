<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\Out\Projection;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\OperclassServiceActionsInterface;
use App\Irc\Application\Port\In\ProtocolServiceActionsInterface;
use App\OperServ\Adapter\In\Event\ForcedVhostApplier;
use App\OperServ\Adapter\In\Event\IrcopModeApplier;
use App\OperServ\Adapter\In\Event\IrcopOperclassApplier;
use App\OperServ\Application\Port\Out\OperatorRoleNetworkProjection;
use App\OperServ\Application\PublishedEvent\OperRoleForcedVhostChangedEvent;
use App\Shared\Application\Port\EventBusInterface;

final readonly class DoctrineOperatorRoleNetworkProjection implements OperatorRoleNetworkProjection
{
    public function __construct(private IrcopModeApplier $modes, private ForcedVhostApplier $vhost, private IrcopOperclassApplier $operclass, private ActiveProtocolModuleHolderInterface $connection, private EventBusInterface $events) {}

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
