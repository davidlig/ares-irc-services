<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\Security\Voter;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\Out\Security\Voter\ChanServLevelFounderVoter;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Security\ChanServPermission;
use App\Irc\Application\Port\In\SenderView;
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

#[CoversClass(ChanServLevelFounderVoter::class)]
final class ChanServLevelFounderVoterTest extends TestCase
{
    #[Test]
    public function delegatesPermissionDecisionUsingContextFacts(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('permission')
            ->with(new OperatorActor('OperUser', 7, true, true), ChanServPermission::LEVEL_FOUNDER)
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RolePermission));

        self::assertSame(VoterInterface::ACCESS_GRANTED, new ChanServLevelFounderVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->createChanServContext($this->sender('OperUser', true, true), new ChanAccountView(7, 'OperUser', 'en')),
            [ChanServPermission::LEVEL_FOUNDER],
        ));
    }

    #[Test]
    public function returnsDeniedDecisionFromAuthorizationBoundary(): void
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('permission')->willReturn(AuthorizationDecision::denied());

        self::assertSame(VoterInterface::ACCESS_DENIED, new ChanServLevelFounderVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->createChanServContext($this->sender('OperUser', false, true), null),
            [ChanServPermission::LEVEL_FOUNDER],
        ));
    }

    #[Test]
    public function deniesWithoutSenderBeforeCallingAuthorization(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::never())->method('permission');

        self::assertSame(VoterInterface::ACCESS_DENIED, new ChanServLevelFounderVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->createChanServContext(null, null),
            [ChanServPermission::LEVEL_FOUNDER],
        ));
    }

    #[Test]
    public function abstainsForUnsupportedAttributeOrSubject(): void
    {
        $voter = new ChanServLevelFounderVoter($this->createStub(OperatorAuthorizationQuery::class));
        $token = $this->createStub(TokenInterface::class);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $this->createChanServContext(null, null), ['chanserv.drop']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new stdClass(), [ChanServPermission::LEVEL_FOUNDER]));
    }

    private function sender(string $nickname, bool $identified, bool $ircOperator): SenderView
    {
        return new SenderView('001ABCD', $nickname, 'oper', 'oper.local', 'oper.local', 'b3Blcg==', $identified, $ircOperator);
    }

    private function createChanServContext(?SenderView $sender, ?ChanAccountView $account): ChanServContext
    {
        $reflection = new ReflectionClass(ChanServContext::class);
        $context = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('sender')->setValue($context, $sender);
        $reflection->getProperty('senderAccount')->setValue($context, $account);

        return $context;
    }
}
