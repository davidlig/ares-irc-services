<?php

declare(strict_types=1);

namespace App\Infrastructure\OperServ\Subscriber;

use App\Domain\IRC\Event\NetworkBurstCompleteEvent;
use App\Domain\OperServ\Entity\OperPermission;
use App\Domain\OperServ\Entity\OperRole;
use App\Domain\OperServ\Repository\OperPermissionRepositoryInterface;
use App\Domain\OperServ\Repository\OperRoleRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use function count;

final class OperServRoleInitializer implements EventSubscriberInterface
{
    private const array DEFAULT_ROLES = [
        [
            'name' => OperRole::ROLE_ADMIN,
            'description' => 'Administrator - full network management',
            'protected' => true,
            'permissions' => [],
        ],
        [
            'name' => OperRole::ROLE_OPER,
            'description' => 'Operator - operational commands',
            'protected' => true,
            'permissions' => [],
        ],
        [
            'name' => OperRole::ROLE_PREOPER,
            'description' => 'Pre-operator - limited commands',
            'protected' => true,
            'permissions' => [],
        ],
    ];

    private const array DEFAULT_PERMISSIONS = [];

    private bool $initialized = false;

    public function __construct(
        private readonly OperRoleRepositoryInterface $roleRepository,
        private readonly OperPermissionRepositoryInterface $permissionRepository,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NetworkBurstCompleteEvent::class => ['onBurstComplete', 50],
        ];
    }

    public function onBurstComplete(NetworkBurstCompleteEvent $event): void
    {
        if ($this->initialized) {
            return;
        }

        $this->initializePermissions();
        $this->initializeRoles();
        $this->initialized = true;
    }

    private function initializePermissions(): void
    {
        foreach (self::DEFAULT_PERMISSIONS as [$name, $description]) {
            $existing = $this->permissionRepository->findByName($name);
            if (null !== $existing) {
                continue;
            }

            $permission = OperPermission::create($name, $description);
            $this->permissionRepository->save($permission);
            $this->logger->debug('OperServ: Created permission', ['name' => $name]);
        }
    }

    private function initializeRoles(): void
    {
        foreach (self::DEFAULT_ROLES as $roleData) {
            $existing = $this->roleRepository->findByName($roleData['name']);
            if (null !== $existing) {
                continue;
            }

            $role = OperRole::create(
                $roleData['name'],
                $roleData['description'],
                $roleData['protected']
            );

            foreach ($roleData['permissions'] as $permName) {
                $permission = $this->permissionRepository->findByName($permName);
                if (null !== $permission) {
                    $role->addPermission($permission);
                }
            }

            $this->roleRepository->save($role);
            $this->logger->info('OperServ: Created role', [
                'name' => $roleData['name'],
                'permissions' => count($roleData['permissions']),
            ]);
        }
    }
}
