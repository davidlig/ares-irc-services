<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\IRC\ServiceBridge;

use App\Domain\IRC\Network\Channel;
use App\Domain\IRC\Network\ChannelMemberRole;
use App\Domain\IRC\Repository\ChannelRepositoryInterface;
use App\Domain\IRC\ValueObject\ChannelName;
use App\Domain\IRC\ValueObject\Uid;
use App\Infrastructure\IRC\ServiceBridge\CoreServiceChannelRegistrationAdapter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function count;

#[CoversClass(CoreServiceChannelRegistrationAdapter::class)]
final class CoreServiceChannelRegistrationAdapterTest extends TestCase
{
    private ChannelRepositoryInterface $repository;

    private CoreServiceChannelRegistrationAdapter $adapter;

    protected function setUp(): void
    {
        $this->repository = new class implements ChannelRepositoryInterface {
            /** @var array<string, Channel> */
            private array $channels = [];

            public function save(Channel $channel): void
            {
                $this->channels[strtolower($channel->name->value)] = $channel;
            }

            public function remove(ChannelName $name): void
            {
                unset($this->channels[strtolower($name->value)]);
            }

            public function findByName(ChannelName $name): ?Channel
            {
                return $this->channels[strtolower($name->value)] ?? null;
            }

            public function all(): array
            {
                return array_values($this->channels);
            }

            public function count(): int
            {
                return count($this->channels);
            }
        };

        $this->adapter = new CoreServiceChannelRegistrationAdapter(
            $this->repository,
            new NullLogger(),
        );
    }

    #[Test]
    public function registerServiceChannelJoinCreatesNewChannelWhenNotExists(): void
    {
        $this->adapter->registerServiceChannelJoin('#ircops', '001CS', 'o', 1700000000, '+nt');

        $channel = $this->repository->findByName(new ChannelName('#ircops'));
        self::assertNotNull($channel);
        self::assertSame('#ircops', $channel->name->value);
        self::assertSame(1700000000, $channel->getCreatedAt()->getTimestamp());
        self::assertTrue($channel->isMember(new Uid('001CS')));
    }

    #[Test]
    public function registerServiceChannelJoinAddsServiceToExistingChannel(): void
    {
        $existing = new Channel(new ChannelName('#ircops'), '+nt', new DateTimeImmutable('@1700000000'));
        $existing->syncMember(new Uid('001USER'), ChannelMemberRole::Op);
        $this->repository->save($existing);

        $this->adapter->registerServiceChannelJoin('#ircops', '001CS', 'q', 1700000000);

        $channel = $this->repository->findByName(new ChannelName('#ircops'));
        self::assertNotNull($channel);
        self::assertTrue($channel->isMember(new Uid('001CS')));
        self::assertTrue($channel->isMember(new Uid('001USER')));
        self::assertSame(2, $channel->getMemberCount());
    }

    #[Test]
    public function registerServiceChannelJoinUpdatesTimestampWhenLower(): void
    {
        $existing = new Channel(new ChannelName('#ircops'), '+nt', new DateTimeImmutable('@1700000100'));
        $this->repository->save($existing);

        $this->adapter->registerServiceChannelJoin('#ircops', '001CS', 'o', 1699999000);

        $channel = $this->repository->findByName(new ChannelName('#ircops'));
        self::assertNotNull($channel);
        self::assertSame(1699999000, $channel->getCreatedAt()->getTimestamp());
    }

    #[Test]
    public function registerServiceChannelJoinDoesNotUpdateTimestampWhenHigher(): void
    {
        $existing = new Channel(new ChannelName('#ircops'), '+nt', new DateTimeImmutable('@1700000000'));
        $this->repository->save($existing);

        $this->adapter->registerServiceChannelJoin('#ircops', '001CS', 'o', 1700001000);

        $channel = $this->repository->findByName(new ChannelName('#ircops'));
        self::assertNotNull($channel);
        self::assertSame(1700000000, $channel->getCreatedAt()->getTimestamp());
    }

    #[Test]
    public function registerServiceChannelJoinDefaultsToOpWhenPrefixInvalid(): void
    {
        $this->adapter->registerServiceChannelJoin('#ircops', '001CS', '', 1700000000);

        $channel = $this->repository->findByName(new ChannelName('#ircops'));
        self::assertNotNull($channel);
        $member = $channel->getMember(new Uid('001CS'));
        self::assertNotNull($member);
        self::assertSame(ChannelMemberRole::Op, $member->role);
    }

    #[Test]
    public function registerServiceChannelJoinWithOwnerPrefix(): void
    {
        $this->adapter->registerServiceChannelJoin('#ircops', '001CS', 'q', 1700000000);

        $channel = $this->repository->findByName(new ChannelName('#ircops'));
        self::assertNotNull($channel);
        $member = $channel->getMember(new Uid('001CS'));
        self::assertNotNull($member);
        self::assertSame(ChannelMemberRole::Owner, $member->role);
    }

    #[Test]
    public function registerServiceChannelJoinDoesNotDuplicateExistingMember(): void
    {
        $this->adapter->registerServiceChannelJoin('#ircops', '001CS', 'o', 1700000000);
        $this->adapter->registerServiceChannelJoin('#ircops', '001CS', 'q', 1700000000);

        $channel = $this->repository->findByName(new ChannelName('#ircops'));
        self::assertNotNull($channel);
        self::assertSame(1, $channel->getMemberCount());
    }

    #[Test]
    public function registerServiceChannelJoinWithInvalidChannelNameDoesNothing(): void
    {
        $this->adapter->registerServiceChannelJoin('nohash', '001CS', 'o', 1700000000);

        self::assertSame(0, $this->repository->count());
    }

    #[Test]
    public function unregisterServiceChannelPartRemovesMemberFromChannel(): void
    {
        $this->adapter->registerServiceChannelJoin('#ircops', '001CS', 'o', 1700000000);
        $this->adapter->registerServiceChannelJoin('#ircops', '001USER', 'v', 1700000000);

        $this->adapter->unregisterServiceChannelPart('#ircops', '001CS');

        $channel = $this->repository->findByName(new ChannelName('#ircops'));
        self::assertNotNull($channel);
        self::assertFalse($channel->isMember(new Uid('001CS')));
        self::assertTrue($channel->isMember(new Uid('001USER')));
    }

    #[Test]
    public function unregisterServiceChannelPartRemovesChannelWhenEmpty(): void
    {
        $this->adapter->registerServiceChannelJoin('#ircops', '001CS', 'o', 1700000000);

        $this->adapter->unregisterServiceChannelPart('#ircops', '001CS');

        $channel = $this->repository->findByName(new ChannelName('#ircops'));
        self::assertNull($channel);
    }

    #[Test]
    public function unregisterServiceChannelPartDoesNothingWhenChannelNotFound(): void
    {
        $this->adapter->unregisterServiceChannelPart('#nonexistent', '001CS');

        self::assertSame(0, $this->repository->count());
    }

    #[Test]
    public function unregisterServiceChannelPartWithInvalidChannelNameDoesNothing(): void
    {
        $this->adapter->unregisterServiceChannelPart('nohash', '001CS');

        self::assertSame(0, $this->repository->count());
    }
}
