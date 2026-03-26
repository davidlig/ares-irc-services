<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security\Voter;

use App\Application\Port\SenderView;
use App\Application\Security\IrcopContextInterface;
use App\Infrastructure\NickServ\Security\IrcServiceUser;
use App\Infrastructure\Security\Voter\IdentifiedVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(IdentifiedVoter::class)]
final class IdentifiedVoterTest extends TestCase
{
    #[Test]
    public function supportsIdentifiedAttribute(): void
    {
        $voter = new IdentifiedVoter();
        $context = $this->createStub(IrcopContextInterface::class);
        $token = $this->createTokenWithIdentifiedUser(false);

        self::assertTrue(VoterInterface::ACCESS_ABSTAIN !== $voter->vote($token, $context, ['IDENTIFIED']));
    }

    #[Test]
    public function abstainsForNonIdentifiedAttribute(): void
    {
        $voter = new IdentifiedVoter();
        $context = $this->createStub(IrcopContextInterface::class);
        $token = $this->createTokenWithIdentifiedUser(false);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $context, ['SOME_OTHER_PERMISSION']));
    }

    #[Test]
    public function abstainsForNonContextSubject(): void
    {
        $voter = new IdentifiedVoter();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->createTokenWithIdentifiedUser(false), new stdClass(), ['IDENTIFIED']));
    }

    #[Test]
    public function grantsAccessForIdentifiedUser(): void
    {
        $voter = new IdentifiedVoter();
        $context = $this->createStub(IrcopContextInterface::class);
        $token = $this->createTokenWithIdentifiedUser(true);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $context, ['IDENTIFIED']));
    }

    #[Test]
    public function deniesAccessForNonIdentifiedUser(): void
    {
        $voter = new IdentifiedVoter();
        $context = $this->createStub(IrcopContextInterface::class);
        $token = $this->createTokenWithIdentifiedUser(false);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $context, ['IDENTIFIED']));
    }

    #[Test]
    public function deniesAccessForNonIrcServiceUser(): void
    {
        $voter = new IdentifiedVoter();
        $context = $this->createStub(IrcopContextInterface::class);
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $context, ['IDENTIFIED']));
    }

    private function createTokenWithIdentifiedUser(bool $isIdentified): TokenInterface
    {
        $senderView = new SenderView(
            uid: 'UID123',
            nick: 'TestNick',
            ident: 'test',
            hostname: 'test.host',
            cloakedHost: 'test.cloak',
            ipBase64: 'dGVzdA==',
            isIdentified: $isIdentified,
        );

        $user = new IrcServiceUser($senderView);

        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }
}
