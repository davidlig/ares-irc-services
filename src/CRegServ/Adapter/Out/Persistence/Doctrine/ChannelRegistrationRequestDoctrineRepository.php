<?php

declare(strict_types=1);

namespace App\CRegServ\Adapter\Out\Persistence\Doctrine;

use App\CRegServ\Application\Port\Out\ChannelRegistrationRequestRepositoryInterface;
use App\CRegServ\Domain\Entity\ChannelRegistrationRequest;
use App\CRegServ\Domain\ValueObject\ChannelRegistrationStatus;
use Doctrine\ORM\EntityManagerInterface;

use function array_map;
use function strtolower;

final readonly class ChannelRegistrationRequestDoctrineRepository implements ChannelRegistrationRequestRepositoryInterface
{
    public function __construct(private EntityManagerInterface $em) {}

    public function save(ChannelRegistrationRequest $request): void
    {
        $this->em->persist($request);
        $this->em->flush();
    }

    public function findByChannel(string $channelLower): ?ChannelRegistrationRequest
    {
        return $this->em
            ->getRepository(ChannelRegistrationRequest::class)
            ->findOneBy(['channelLower' => strtolower($channelLower)]);
    }

    public function findLastRequestByAccount(int $requesterAccountId): ?ChannelRegistrationRequest
    {
        return $this->em
            ->getRepository(ChannelRegistrationRequest::class)
            ->findOneBy(
                ['requesterAccountId' => $requesterAccountId],
                ['createdAt' => 'DESC'],
            );
    }

    public function findByStatuses(array $statuses): array
    {
        if ([] === $statuses) {
            return [];
        }

        return $this->em
            ->getRepository(ChannelRegistrationRequest::class)
            ->findBy(['status' => $statuses], ['createdAt' => 'DESC']);
    }

    /**
     * @param string[]                    $channelLowers
     * @param ChannelRegistrationStatus[] $statuses
     *
     * @return array<string, ChannelRegistrationRequest> keyed by channel_lower
     */
    public function findByChannels(array $channelLowers, array $statuses): array
    {
        if ([] === $channelLowers || [] === $statuses) {
            return [];
        }

        $normalized = array_map(static fn (string $channel): string => strtolower($channel), $channelLowers);

        $requests = $this->em
            ->getRepository(ChannelRegistrationRequest::class)
            ->findBy(['channelLower' => $normalized, 'status' => $statuses]);

        $indexed = [];
        foreach ($requests as $request) {
            $indexed[$request->getChannelLower()] = $request;
        }

        return $indexed;
    }

    public function delete(ChannelRegistrationRequest $request): void
    {
        $this->em->remove($request);
        $this->em->flush();
    }
}
