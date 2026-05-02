<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ;

use App\Application\NickServ\SessionLanguageRegistry;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Domain\NickServ\Repository\RegisteredNickRepositoryInterface;
use App\Infrastructure\NickServ\UserLanguageResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UserLanguageResolver::class)]
final class UserLanguageResolverTest extends TestCase
{
    #[Test]
    public function resolveReturnsAccountLanguage(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $resolver = new UserLanguageResolver($nickRepository, new SessionLanguageRegistry(), 'en');

        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getLanguage')->willReturn('es');

        $nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($nick);

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
        );

        self::assertSame('es', $resolver->resolve($sender));
    }

    #[Test]
    public function resolveReturnsDefaultLanguageWhenNoAccount(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $resolver = new UserLanguageResolver($nickRepository, new SessionLanguageRegistry(), 'en');

        $nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('Unregistered')->willReturn(null);

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'Unregistered',
            ident: 'user',
            hostname: 'user.local',
            cloakedHost: 'user.local',
            ipBase64: '',
        );

        self::assertSame('en', $resolver->resolve($sender));
    }

    #[Test]
    public function resolveReturnsSessionLanguageWhenNoAccount(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $sessionRegistry = new SessionLanguageRegistry();
        $sessionRegistry->register('001ABCD', 'fr');
        $resolver = new UserLanguageResolver($nickRepository, $sessionRegistry, 'en');

        $nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('Unregistered')->willReturn(null);

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'Unregistered',
            ident: 'user',
            hostname: 'user.local',
            cloakedHost: 'user.local',
            ipBase64: '',
        );

        self::assertSame('fr', $resolver->resolve($sender));
    }

    #[Test]
    public function resolveByNickReturnsAccountLanguage(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $resolver = new UserLanguageResolver($nickRepository, new SessionLanguageRegistry(), 'en');

        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getLanguage')->willReturn('es');

        $nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($nick);

        self::assertSame('es', $resolver->resolveByNick('TestUser'));
    }

    #[Test]
    public function resolveByNickReturnsDefaultWhenNotFound(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $resolver = new UserLanguageResolver($nickRepository, new SessionLanguageRegistry(), 'en');

        $nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('NonExistent')->willReturn(null);

        self::assertSame('en', $resolver->resolveByNick('NonExistent'));
    }

    #[Test]
    public function resolveByNickPreservesOriginalCase(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $resolver = new UserLanguageResolver($nickRepository, new SessionLanguageRegistry(), 'en');

        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getLanguage')->willReturn('es');

        $nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($nick);

        self::assertSame('es', $resolver->resolveByNick('TestUser'));
    }

    #[Test]
    public function getDefaultReturnsConfiguredDefault(): void
    {
        $nickRepository = $this->createStub(RegisteredNickRepositoryInterface::class);
        $resolver = new UserLanguageResolver($nickRepository, new SessionLanguageRegistry(), 'fr');

        self::assertSame('fr', $resolver->getDefault());
    }

    #[Test]
    public function resolveUsesDefaultFromConstructor(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $resolver = new UserLanguageResolver($nickRepository, new SessionLanguageRegistry(), 'es');

        $nickRepository->expects(self::atLeastOnce())->method('findByNick')->willReturn(null);

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'Test',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: '',
        );

        self::assertSame('es', $resolver->resolve($sender));
    }

    #[Test]
    public function accountLanguageTakesPrecedenceOverSession(): void
    {
        $nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $sessionRegistry = new SessionLanguageRegistry();
        $sessionRegistry->register('001ABCD', 'fr');
        $resolver = new UserLanguageResolver($nickRepository, $sessionRegistry, 'en');

        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getLanguage')->willReturn('pt');

        $nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($nick);

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: '',
        );

        self::assertSame('pt', $resolver->resolve($sender));
    }

    #[Test]
    public function resolveFromAccountReturnsAccountLanguageWhenAccountProvided(): void
    {
        $resolver = new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en');

        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getLanguage')->willReturn('pl');

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: '',
        );

        self::assertSame('pl', $resolver->resolveFromAccount($sender, $nick));
    }

    #[Test]
    public function resolveFromAccountReturnsSessionLanguageWhenAccountNull(): void
    {
        $sessionRegistry = new SessionLanguageRegistry();
        $sessionRegistry->register('001ABCD', 'el');
        $resolver = new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), $sessionRegistry, 'en');

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: '',
        );

        self::assertSame('el', $resolver->resolveFromAccount($sender, null));
    }

    #[Test]
    public function resolveFromAccountReturnsDefaultWhenAccountNullAndNoSession(): void
    {
        $resolver = new UserLanguageResolver($this->createStub(RegisteredNickRepositoryInterface::class), new SessionLanguageRegistry(), 'en');

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: '',
        );

        self::assertSame('en', $resolver->resolveFromAccount($sender, null));
    }
}
