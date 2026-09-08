<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security\Voter;

use App\Application\Security\IrcopContextInterface;
use App\Infrastructure\Security\Voter\IdentifiedVoter;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
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
    public function delegatesIdentifiedAccountDecisionUsingSubjectFacts(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('identifiedAccount')
            ->with(new OperatorActor('Alice', 42, true, false))
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::IdentifiedAccount));

        self::assertSame(VoterInterface::ACCESS_GRANTED, new IdentifiedVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->authorizationSubject('Alice', 42, true, false),
            ['IDENTIFIED'],
        ));
    }

    #[Test]
    public function returnsDeniedDecisionFromAuthorizationBoundary(): void
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('identifiedAccount')->willReturn(AuthorizationDecision::denied());

        self::assertSame(VoterInterface::ACCESS_DENIED, new IdentifiedVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->authorizationSubject('Alice', null, false, false),
            ['IDENTIFIED'],
        ));
    }

    #[Test]
    public function deniesWhenSubjectHasNoNickname(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::never())->method('identifiedAccount');

        self::assertSame(VoterInterface::ACCESS_DENIED, new IdentifiedVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->authorizationSubject(null, null, false, false),
            ['IDENTIFIED'],
        ));
    }

    #[Test]
    public function abstainsForUnsupportedAttributeOrSubject(): void
    {
        $voter = new IdentifiedVoter($this->createStub(OperatorAuthorizationQuery::class));
        $token = $this->createStub(TokenInterface::class);
        $context = $this->authorizationSubject('Alice', 42, true, false);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $context, ['OTHER']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new stdClass(), ['IDENTIFIED']));
    }

    private function authorizationSubject(?string $nickname, ?int $accountId, bool $identified, bool $ircOperator): IrcopContextInterface
    {
        $subject = $this->createStub(IrcopContextInterface::class);
        $subject->method('getSenderNickname')->willReturn($nickname);
        $subject->method('getSenderAccountId')->willReturn($accountId);
        $subject->method('isSenderIdentified')->willReturn($identified);
        $subject->method('isSenderIrcOperator')->willReturn($ircOperator);

        return $subject;
    }
}
