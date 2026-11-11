<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\Out\Persistence\Doctrine;

use App\ChanServ\Application\Model\BurstChannelPage;
use App\ChanServ\Application\Port\Out\BurstChannelPages;
use App\ChanServ\Application\Port\Out\ChannelAkickRepositoryInterface;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Domain\Entity\ChannelAccess;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;

use function array_map;
use function array_values;
use function count;

final readonly class BurstChannelPageSource implements BurstChannelPages
{
    public function __construct(
        private RegisteredChannelRepositoryInterface $channels,
        private ChannelAkickRepositoryInterface $akicks,
        private EntityManagerInterface $em,
        private int $pageSize = 200,
    ) {
        if (1 > $this->pageSize) {
            throw new InvalidArgumentException('Burst page size must be positive.');
        }
    }

    public function pages(): iterable
    {
        $channels = [];
        foreach ($this->channels->iterateAll() as $channel) {
            $channels[] = $channel;
            if (count($channels) === $this->pageSize) {
                yield from $this->page($channels);
                $channels = [];
            }
        }
        if ([] !== $channels) {
            yield from $this->page($channels);
        }
    }

    /**
     * @param list<RegisteredChannel> $channels
     *
     * @return iterable<BurstChannelPage>
     */
    private function page(array $channels): iterable
    {
        $ids = array_map(static fn (RegisteredChannel $channel): int => $channel->getId(), $channels);
        $access = $this->em->getRepository(ChannelAccess::class)->findBy(['channelId' => $ids]);
        $levels = $this->em->getRepository(ChannelLevel::class)->findBy(['channelId' => $ids]);
        $akicks = array_values($this->akicks->findByChannelIds($ids));
        try {
            yield new BurstChannelPage($channels, $access, $levels, $akicks);
        } finally {
            foreach ([$access, $levels, $akicks] as $entries) {
                foreach ($entries as $entry) {
                    $this->em->detach($entry);
                }
            }
        }
    }
}
