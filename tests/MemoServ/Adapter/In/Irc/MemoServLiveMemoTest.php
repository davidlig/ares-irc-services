<?php

declare(strict_types=1);

namespace App\Tests\MemoServ\Adapter\In\Irc;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameProviderInterface;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Adapter\In\Irc\Command\DelCommand;
use App\MemoServ\Adapter\In\Irc\Command\HelpCommand;
use App\MemoServ\Adapter\In\Irc\Command\SendCommand;
use App\MemoServ\Adapter\In\Irc\Help\UnifiedHelpFormatter;
use App\MemoServ\Adapter\In\Irc\MemoAuthorizationCheckerInterface;
use App\MemoServ\Adapter\In\Irc\MemoAuthorizationContextInterface;
use App\MemoServ\Adapter\In\Irc\MemoNoticePresenter;
use App\MemoServ\Adapter\In\Irc\MemoServCommandRegistry;
use App\MemoServ\Adapter\In\Irc\MemoServContext;
use App\MemoServ\Adapter\In\Irc\MemoServNotifierInterface;
use App\MemoServ\Adapter\In\Irc\MemoServService;
use App\MemoServ\Adapter\In\Irc\MemoServUserPresentationPreferences;
use App\MemoServ\Adapter\Out\Persistence\Doctrine\MemoDoctrineRepository;
use App\MemoServ\Application\Model\MemoAccountView;
use App\MemoServ\Application\Model\MemoChannelView;
use App\MemoServ\Application\Port\Out\MemoChannelPort;
use App\MemoServ\Application\Port\Out\MemoIgnoreRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoSettingsRepositoryInterface;
use App\MemoServ\Application\Port\Out\MemoThrottlePort;
use App\MemoServ\Application\Port\Out\MemoUserAccountPort;
use App\MemoServ\Application\UseCase\Del\DelMemoHandler;
use App\MemoServ\Application\UseCase\GetPendingChannelNotice\GetPendingChannelNoticeHandler;
use App\MemoServ\Application\UseCase\Send\SendMemoHandler;
use App\MemoServ\Domain\Entity\Memo;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Application\Port\EventBusInterface;
use App\Tests\Shared\DoctrineIntegrationTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Translation\Translator;

use function array_last;
use function dirname;
use function implode;

#[CoversNothing]
#[Group('integration')]
final class MemoServLiveMemoTest extends DoctrineIntegrationTestCase
{
    private MemoDoctrineRepository $repository;

    private MemoServService $service;

    private bool $canManage = true;

    private bool $connected = true;

    /** @var list<string> */
    private array $messages = [];

    /** @var list<array{string, string}> */
    private array $notices = [];

    public function testDelAllChangesOnlySelectedInboxAndGracefullyReportsEmpty(): void
    {
        $now = new DateTimeImmutable();
        foreach ([new Memo(1, null, 2, 'Received', $now), new Memo(1, null, 3, 'Read', $now), new Memo(2, null, 1, 'Sent', $now), new Memo(null, 5, 1, 'Channel', $now)] as $memo) {
            $this->repository->save($memo);
        }
        $this->service->dispatch('DEL aLl', $this->sender());
        $this->flushAndClear();
        self::assertSame(['+ All messages in this inbox have been deleted.'], $this->messages());
        self::assertSame([], $this->repository->findByTargetNick(1));
        self::assertSame('Sent', $this->repository->findByTargetNick(2)[0]->getMessage());
        self::assertCount(1, $this->repository->findByTargetChannel(5));
        $this->service->dispatch('DEL ALL', $this->sender());
        self::assertSame('i There are no messages to delete.', $this->messages()[1]);
        $this->service->dispatch('DEL #room ALL', $this->sender());
        $this->flushAndClear();
        self::assertSame([], $this->repository->findByTargetChannel(5));
        self::assertCount(1, $this->repository->findByTargetNick(2));
        $this->service->dispatch('DEL #room all', $this->sender());
        self::assertSame('i There are no messages to delete.', $this->messages()[3]);
    }

    public function testRejectionsPreserveDataAndNumericDelStillWorks(): void
    {
        $this->repository->save(new Memo(null, 5, 2, 'Keep', new DateTimeImmutable()));
        $this->repository->save(new Memo(1, null, 2, 'Personal', new DateTimeImmutable()));
        $before = $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM memos');
        $this->canManage = false;
        $this->service->dispatch('DEL #room ALL', $this->sender());
        self::assertStringContainsString('DEL', $this->messages()[0]);
        self::assertStringContainsString('#room', $this->messages()[0]);
        self::assertStringStartsWith('!', $this->messages()[0]);
        foreach (['DEL #room', 'DEL nonsense', 'DEL #room ALLL', 'DEL'] as $input) {
            $this->service->dispatch($input, $this->sender());
            self::assertStringContainsString('DEL [#channel] {number|ALL}', $this->lastMessage());
        }
        $this->service->dispatch('DEL ALL', $this->sender(false));
        self::assertStringStartsWith('!', $this->lastMessage());
        self::assertSame($before, $this->entityManager->getConnection()->fetchAllAssociative('SELECT * FROM memos'));
        $this->service->dispatch('DEL 1', $this->sender());
        self::assertSame('+ Message #1 deleted.', $this->lastMessage());
        self::assertSame([], $this->repository->findByTargetNick(1));
        self::assertCount(1, $this->repository->findByTargetChannel(5));
    }

    public function testSendPersistsBeforeLiveNickAndAuthorizedChannelNotices(): void
    {
        $this->service->dispatch('SEND Bob Hello', $this->sender());
        self::assertCount(1, $this->repository->findByTargetNick(2));
        self::assertSame([['Bob', 'i You received a new memo from Alice. Read it with /msg MemoBot LIST']], $this->notices());
        $this->connected = false;
        $this->service->dispatch('SEND Bob Offline', $this->sender());
        self::assertCount(2, $this->repository->findByTargetNick(2));
        self::assertCount(1, $this->notices());
        $this->connected = true;
        $this->service->dispatch('SEND #room Staff', $this->sender());
        self::assertCount(1, $this->repository->findByTargetChannel(5));
        self::assertSame(['Bob', 'i A new memo from Alice has arrived for #room. Read it with /msg MemoBot LIST #room'], $this->notices()[1]);
        self::assertCount(2, $this->notices());
        $this->service->dispatch('SEND Unknown Rejected', $this->sender());
        self::assertSame([
            ['Bob', 'i You received a new memo from Alice. Read it with /msg MemoBot LIST'],
            ['Bob', 'i A new memo from Alice has arrived for #room. Read it with /msg MemoBot LIST #room'],
        ], $this->notices());
    }

    public function testHelpDelShowsAllSyntaxAndExamples(): void
    {
        $this->service->dispatch('HELP DEL', $this->sender());
        $output = implode("\n", $this->messages());
        self::assertStringContainsString('DEL [#channel] {number|ALL}', $output);
        self::assertStringContainsString('/msg MemoBot DEL ALL', $output);
        self::assertStringContainsString('/msg MemoBot DEL #help ALL', $output);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = new MemoDoctrineRepository($this->entityManager);
        $channel = $this->createStub(MemoChannelPort::class);
        $channel->method('findChannelByName')->willReturn(new MemoChannelView(5, '#room'));
        $channel->method('canManageChannelMemos')->willReturnCallback(fn (): bool => $this->canManage);
        $channel->method('canReadChannelMemos')->willReturnCallback(static fn (int $channelId, int $nickId): bool => 2 === $nickId);
        $accounts = $this->createStub(MemoUserAccountPort::class);
        $accounts->method('findAccountByNick')->willReturnCallback(static fn (string $nick): ?MemoAccountView => match ($nick) {
            'Alice' => new MemoAccountView(1, 'Alice', 'en'),
            'Bob' => new MemoAccountView(2, 'Bob', 'en'),
            'Denied' => new MemoAccountView(3, 'Denied', 'en'),
            default => null,
        });
        $settings = $this->createStub(MemoSettingsRepositoryInterface::class);
        $settings->method('isEnabledForNick')->willReturn(true);
        $settings->method('isEnabledForChannel')->willReturn(true);
        $lookup = $this->createStub(NetworkUserLookupPort::class);
        $lookup->method('findByNick')->willReturnCallback(fn (string $nick): ?SenderView => $this->connected ? new SenderView($nick, $nick, 'i', 'h', 'c', 'ip', true) : null);
        $lookup->method('listConnectedUids')->willReturn(['Bob', 'Denied', 'Guest']);
        $lookup->method('findByUid')->willReturnCallback(static fn (string $uid): SenderView => new SenderView($uid, 'Guest' === $uid ? 'Bob' : $uid, 'i', 'h', 'c', 'ip', 'Guest' !== $uid));
        $notifier = $this->createStub(MemoServNotifierInterface::class);
        $notifier->method('getNick')->willReturn('MemoBot');
        $notifier->method('sendMessage')->willReturnCallback(function (string $uid, string $text): void { $this->messages[] = $text; });
        $notifier->method('sendNotice')->willReturnCallback(function (string $uid, string $text): void { $this->notices[] = [$uid, $text]; });
        $translator = new Translator('en');
        $translator->addLoader('yaml', new IrcYamlTranslationLoader());
        foreach (['memoserv', 'common'] as $domain) {
            $translator->addResource('yaml', dirname(__DIR__, 5) . '/translations/' . $domain . '.en.yaml', 'en', $domain);
        }
        $renderer = new IrcRenderer(new FixedStyleProvider(new PresentationStyle(markers: ['information' => 'i', 'success' => '+', 'error' => '!'])));
        $query = new GetPendingChannelNoticeHandler($channel, $accounts, $this->repository, $settings);
        $send = new SendMemoHandler($accounts, $channel, $this->repository, $this->createStub(MemoIgnoreRepositoryInterface::class), $settings, $this->createStub(MemoThrottlePort::class), 50, 50, 0);
        $registry = new MemoServCommandRegistry([new DelCommand(new DelMemoHandler($channel, $this->repository)), new SendCommand($send, $lookup, $translator, $query, new MemoNoticePresenter($translator, $renderer)), new HelpCommand(new UnifiedHelpFormatter())]);
        $preferences = $this->createStub(MemoServUserPresentationPreferences::class);
        $preferences->method('languageFor')->willReturn('en');
        $authorization = $this->createStub(MemoAuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $permission, MemoServContext $context): bool => 'IDENTIFIED' !== $permission || $context->isSenderIdentified());
        $provider = $this->createStub(ServiceNicknameProviderInterface::class);
        $provider->method('getServiceKey')->willReturn('memoserv');
        $provider->method('getNickname')->willReturn('MemoBot');
        $this->service = new MemoServService($registry, $accounts, $preferences, $notifier, $preferences, $translator, new ServiceNicknameRegistry([$provider]), $this->createStub(MemoAuthorizationContextInterface::class), $authorization, $this->createStub(EventBusInterface::class), renderer: $renderer);
    }

    /** @return list<string> */
    private function messages(): array
    {
        return $this->messages;
    }

    private function lastMessage(): string
    {
        return array_last($this->messages) ?? '';
    }

    /** @return list<array{string, string}> */
    private function notices(): array
    {
        return $this->notices;
    }

    private function sender(bool $identified = true): SenderView
    {
        return new SenderView('Alice', 'Alice', 'i', 'h', 'c', 'ip', $identified);
    }
}
