<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\Out\Persistence\Doctrine;

use App\MemoServ\Adapter\Out\Persistence\Doctrine\MemoDoctrineRepository;
use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\List\ListMemos;
use App\MemoServ\Application\UseCase\List\ListMemosHandler;
use App\MemoServ\Application\UseCase\Read\ReadMemo;
use App\MemoServ\Application\UseCase\Read\ReadMemoHandler;
use App\MemoServ\Domain\Entity\Memo;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function hash;
use function serialize;

#[CoversClass(ListMemosHandler::class)]
#[CoversClass(ReadMemoHandler::class)]
final class MemoSenderIntegrityTest extends DoctrineIntegrationTestCase
{
    #[DataProvider('commands')]
    #[Test]
    public function rejectsPersistedMissingSenderWithoutChangingTheMemo(bool $read, bool $channel): void
    {
        $repository = new MemoDoctrineRepository($this->entityManager);
        $memo = new Memo($channel ? null : 10, $channel ? 5 : null, 20, 'Historical memo', new DateTimeImmutable('2026-10-27 12:00:00 UTC'));
        $repository->save($memo);
        $connection = $this->entityManager->getConnection();
        // Model a damaged stored row, not an impossible constructor or mocked entity.
        $connection->update('memos', ['sender_nick_id' => null, 'sender_name' => null], ['id' => $memo->getId()]);
        $this->entityManager->clear();
        $before = hash('sha256', serialize($connection->fetchAllAssociative('SELECT * FROM memos ORDER BY id')));
        $accounts = $this->createMock(MemoUserAccountPort::class);
        $accounts->expects(self::never())->method('findNicknameById');
        $channels = $this->createStub(MemoChannelPort::class);
        $channels->method('findChannelByName')->willReturn(new MemoChannelView(5, '#example'));
        $channels->method('canReadChannelMemos')->willReturn(true);

        try {
            if ($read) {
                new ReadMemoHandler($accounts, $channels, $repository)->handle(new ReadMemo(10, $channel ? '#example' : null, 1, new DateTimeImmutable('2026-10-27 13:00:00 UTC')));
            } else {
                new ListMemosHandler($accounts, $channels, $repository)->handle(new ListMemos(10, 'Requester', $channel ? '#example' : null));
            }
            self::fail('Expected missing persisted sender to be rejected.');
        } catch (LogicException $exception) {
            self::assertSame('Memo sender representation is missing.', $exception->getMessage());
        } finally {
            self::assertSame($before, hash('sha256', serialize($connection->fetchAllAssociative('SELECT * FROM memos ORDER BY id'))));
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function commands(): iterable
    {
        yield 'nickname LIST' => [false, false];
        yield 'nickname READ' => [true, false];
        yield 'channel LIST' => [false, true];
        yield 'channel READ' => [true, true];
    }
}
