<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\NickServ\Security\Voter;

use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\Port\SenderView;
use App\Domain\NickServ\Entity\RegisteredNick;
use App\Infrastructure\NickServ\Security\Voter\NickServIdentifiedOwnerVoter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(NickServIdentifiedOwnerVoter::class)]
final class NickServIdentifiedOwnerVoterTest extends TestCase
{
    private NickServIdentifiedOwnerVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new NickServIdentifiedOwnerVoter();
    }

    #[Test]
    public function voteGrantsAccessForIdentifiedOwner(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
        );

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('TestUser');

        $context = $this->createNickServContext($sender, $account);
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, $context, [NickServPermission::IDENTIFIED_OWNER]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function voteDeniesAccessWhenNotIdentified(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: 'dGVzdA==',
            isIdentified: false,
        );

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('TestUser');

        $context = $this->createNickServContext($sender, $account);
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, $context, [NickServPermission::IDENTIFIED_OWNER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteDeniesAccessWhenAccountIsNull(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
        );

        $context = $this->createNickServContext($sender, null);
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, $context, [NickServPermission::IDENTIFIED_OWNER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteDeniesAccessWhenSenderIsNull(): void
    {
        $context = $this->createNickServContext(null, null);
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, $context, [NickServPermission::IDENTIFIED_OWNER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteDeniesAccessWhenDifferentNickname(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'TestUser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
        );

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('OtherUser');

        $context = $this->createNickServContext($sender, $account);
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, $context, [NickServPermission::IDENTIFIED_OWNER]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    #[Test]
    public function voteGrantsAccessCaseInsensitive(): void
    {
        $sender = new SenderView(
            uid: '001ABCD',
            nick: 'testuser',
            ident: 'test',
            hostname: 'test.local',
            cloakedHost: 'test.local',
            ipBase64: 'dGVzdA==',
            isIdentified: true,
        );

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getNickname')->willReturn('TESTUSER');

        $context = $this->createNickServContext($sender, $account);
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, $context, [NickServPermission::IDENTIFIED_OWNER]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    #[Test]
    public function voteAbstainsForUnsupportedAttribute(): void
    {
        $context = $this->createNickServContext(null, null);
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, $context, ['OTHER_ATTRIBUTE']);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    #[Test]
    public function voteAbstainsForNonNickServContextSubject(): void
    {
        $token = $this->createStub(TokenInterface::class);

        $result = $this->voter->vote($token, new stdClass(), [NickServPermission::IDENTIFIED_OWNER]);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    private function createNickServContext(?SenderView $sender, ?RegisteredNick $account): NickServContext
    {
        $reflection = new ReflectionClass(NickServContext::class);
        $context = $reflection->newInstanceWithoutConstructor();

        $senderProp = $reflection->getProperty('sender');
        $senderProp->setAccessible(true);
        $senderProp->setValue($context, $sender);

        $accountProp = $reflection->getProperty('senderAccount');
        $accountProp->setAccessible(true);
        $accountProp->setValue($context, $account);

        return $context;
    }
}
