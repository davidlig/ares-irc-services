<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\Network;

use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Infrastructure\IRC\Network\InMemoryChannelRepository;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InMemoryChannelRepository::class)]
final class InMemoryChannelRepositoryTest extends TestCase
{
    private InMemoryChannelRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryChannelRepository();
    }

    private function createChannel(string $name): Channel
    {
        return new Channel(new ChannelName($name), '', new DateTimeImmutable());
    }

    #[Test]
    public function saveStoresChannel(): void
    {
        $channel = $this->createChannel('#test');

        $this->repository->save($channel);

        self::assertSame($channel, $this->repository->findByName(new ChannelName('#test')));
    }

    #[Test]
    public function findByNameReturnsNullWhenNotFound(): void
    {
        self::assertNull($this->repository->findByName(new ChannelName('#nonexistent')));
    }

    #[Test]
    public function findByNameIsCaseInsensitive(): void
    {
        $channel = $this->createChannel('#Test');
        $this->repository->save($channel);

        self::assertSame($channel, $this->repository->findByName(new ChannelName('#Test')));
        self::assertSame($channel, $this->repository->findByName(new ChannelName('#test')));
        self::assertSame($channel, $this->repository->findByName(new ChannelName('#TEST')));
    }

    #[Test]
    public function removeDeletesChannel(): void
    {
        $channel = $this->createChannel('#test');
        $this->repository->save($channel);

        $this->repository->remove(new ChannelName('#test'));

        self::assertNull($this->repository->findByName(new ChannelName('#test')));
    }

    #[Test]
    public function removeIsCaseInsensitive(): void
    {
        $channel = $this->createChannel('#Test');
        $this->repository->save($channel);

        $this->repository->remove(new ChannelName('#test'));

        self::assertNull($this->repository->findByName(new ChannelName('#Test')));
    }

    #[Test]
    public function removeDoesNothingWhenNotFound(): void
    {
        $this->repository->remove(new ChannelName('#nonexistent'));

        self::assertNull($this->repository->findByName(new ChannelName('#nonexistent')));
    }

    #[Test]
    public function allReturnsAllChannels(): void
    {
        $channel1 = $this->createChannel('#alpha');
        $channel2 = $this->createChannel('#beta');
        $this->repository->save($channel1);
        $this->repository->save($channel2);

        $all = $this->repository->all();

        self::assertCount(2, $all);
        self::assertContains($channel1, $all);
        self::assertContains($channel2, $all);
    }

    #[Test]
    public function allReturnsEmptyArrayWhenNoChannels(): void
    {
        self::assertSame([], $this->repository->all());
    }

    #[Test]
    public function countReturnsNumberOfChannels(): void
    {
        self::assertSame(0, $this->repository->count());

        $this->repository->save($this->createChannel('#alpha'));
        self::assertSame(1, $this->repository->count());

        $this->repository->save($this->createChannel('#beta'));
        self::assertSame(2, $this->repository->count());
    }

    #[Test]
    public function saveOverwritesExistingChannel(): void
    {
        $channel1 = $this->createChannel('#test');
        $channel2 = $this->createChannel('#test');

        $this->repository->save($channel1);
        $this->repository->save($channel2);

        self::assertSame($channel2, $this->repository->findByName(new ChannelName('#test')));
        self::assertSame(1, $this->repository->count());
    }
}
