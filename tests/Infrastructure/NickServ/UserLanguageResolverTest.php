<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ;

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
    private RegisteredNickRepositoryInterface $nickRepository;

    private UserLanguageResolver $resolver;

    protected function setUp(): void
    {
        $this->nickRepository = $this->createMock(RegisteredNickRepositoryInterface::class);
        $this->resolver = new UserLanguageResolver($this->nickRepository, 'en');
    }

    #[Test]
    public function resolveReturnsAccountLanguage(): void
    {
        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getLanguage')->willReturn('es');

        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($nick);

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
        );

        self::assertSame('es', $this->resolver->resolve($sender));
    }

    #[Test]
    public function resolveReturnsDefaultLanguageWhenNoAccount(): void
    {
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('Unregistered')->willReturn(null);

        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'Unregistered',
            ident: 'user',
            hostname: 'user.local',
            cloakedHost: 'user.local',
            ipBase64: '',
        );

        self::assertSame('en', $this->resolver->resolve($sender));
    }

    #[Test]
    public function resolveByNickReturnsAccountLanguage(): void
    {
        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getLanguage')->willReturn('es');

        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($nick);

        self::assertSame('es', $this->resolver->resolveByNick('TestUser'));
    }

    #[Test]
    public function resolveByNickReturnsDefaultWhenNotFound(): void
    {
        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('NonExistent')->willReturn(null);

        self::assertSame('en', $this->resolver->resolveByNick('NonExistent'));
    }

    #[Test]
    public function resolveByNickPreservesOriginalCase(): void
    {
        $nick = $this->createStub(RegisteredNick::class);
        $nick->method('getLanguage')->willReturn('es');

        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->with('TestUser')->willReturn($nick);

        self::assertSame('es', $this->resolver->resolveByNick('TestUser'));
    }

    #[Test]
    public function getDefaultReturnsConfiguredDefault(): void
    {
        $this->nickRepository->expects(self::never())->method('findByNick');

        $resolver = new UserLanguageResolver($this->nickRepository, 'fr');

        self::assertSame('fr', $resolver->getDefault());
    }

    #[Test]
    public function resolveUsesDefaultFromConstructor(): void
    {
        $resolver = new UserLanguageResolver($this->nickRepository, 'es');

        $this->nickRepository->expects(self::atLeastOnce())->method('findByNick')->willReturn(null);

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
}
