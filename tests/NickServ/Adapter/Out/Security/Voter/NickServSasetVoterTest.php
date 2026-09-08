<?php

declare(strict_types=1);

namespace App\Tests\NickServ\Adapter\Out\Security\Voter;

use App\Irc\Application\Port\In\SenderView;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Adapter\Out\Security\Voter\NickServSasetVoter;
use App\NickServ\Application\Security\NickServPermission;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

#[CoversClass(NickServSasetVoter::class)]
final class NickServSasetVoterTest extends TestCase
{
    #[Test]
    public function delegatesPermissionDecisionUsingContextFacts(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('permission')
            ->with(new OperatorActor('OperUser', 7, true, true), NickServPermission::SASET)
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RolePermission));

        $account = $this->createStub(RegisteredNick::class);
        $account->method('getId')->willReturn(7);

        self::assertSame(VoterInterface::ACCESS_GRANTED, new NickServSasetVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->createNickServContext($this->sender('OperUser', true, true), $account),
            [NickServPermission::SASET],
        ));
    }

    #[Test]
    public function returnsDeniedDecisionFromAuthorizationBoundary(): void
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('permission')->willReturn(AuthorizationDecision::denied());

        self::assertSame(VoterInterface::ACCESS_DENIED, new NickServSasetVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->createNickServContext($this->sender('OperUser', false, true), null),
            [NickServPermission::SASET],
        ));
    }

    #[Test]
    public function deniesWithoutSenderBeforeCallingAuthorization(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::never())->method('permission');

        self::assertSame(VoterInterface::ACCESS_DENIED, new NickServSasetVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->createNickServContext(null, null),
            [NickServPermission::SASET],
        ));
    }

    #[Test]
    public function abstainsForUnsupportedAttributeOrSubject(): void
    {
        $voter = new NickServSasetVoter($this->createStub(OperatorAuthorizationQuery::class));
        $token = $this->createStub(TokenInterface::class);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $this->createNickServContext(null, null), ['OTHER']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new stdClass(), [NickServPermission::SASET]));
    }

    private function sender(string $nickname, bool $identified, bool $ircOperator): SenderView
    {
        return new SenderView('001ABCD', $nickname, 'oper', 'oper.local', 'oper.local', 'b3Blcg==', $identified, $ircOperator);
    }

    private function createNickServContext(?SenderView $sender, ?RegisteredNick $account): NickServContext
    {
        $reflection = new ReflectionClass(NickServContext::class);
        $context = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('sender')->setValue($context, $sender);
        $reflection->getProperty('senderAccount')->setValue($context, $account);

        return $context;
    }
}
