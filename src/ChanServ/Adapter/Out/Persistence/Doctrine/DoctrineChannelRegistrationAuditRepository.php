<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Port\Out\ChannelRegistrationAuditRepository;
use App\ChanServ\Domain\Entity\ChannelRegistrationAudit;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineChannelRegistrationAuditRepository implements ChannelRegistrationAuditRepository
{
    public function __construct(private EntityManagerInterface $entityManager) {}

    public function save(ChannelRegistrationAudit $audit): void
    {
        $this->entityManager->persist($audit);
        $this->entityManager->flush();
    }
}
