<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security\Voter;

use App\Application\Security\IrcopContextInterface;
use App\Infrastructure\Security\Voter\RootVoter;
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

#[CoversClass(RootVoter::class)]
final class RootVoterTest extends TestCase
{
    #[Test]
    public function delegatesRootDecisionUsingSubjectFacts(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('root')
            ->with(new OperatorActor('RootAdmin', 1, true, false))
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RootIdentity));

        self::assertSame(VoterInterface::ACCESS_GRANTED, new RootVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->subject('RootAdmin', 1, true, false),
            ['ROOT'],
        ));
    }

    #[Test]
    public function returnsDeniedDecisionAndDoesNotRequireIrcOperatorStatus(): void
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('root')->willReturn(AuthorizationDecision::denied());

        self::assertSame(VoterInterface::ACCESS_DENIED, new RootVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->subject('RootAdmin', null, false, true),
            ['ROOT'],
        ));
    }

    #[Test]
    public function deniesWithoutNicknameAndAbstainsForUnsupportedInput(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::never())->method('root');
        $voter = new RootVoter($authorization);
        $token = $this->createStub(TokenInterface::class);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $this->subject(null, null, false, false), ['ROOT']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $this->subject('RootAdmin', 1, true, false), ['OTHER']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new stdClass(), ['ROOT']));
    }

    private function subject(?string $nickname, ?int $accountId, bool $identified, bool $ircOperator): IrcopContextInterface
    {
        $subject = $this->createStub(IrcopContextInterface::class);
        $subject->method('getSenderNickname')->willReturn($nickname);
        $subject->method('getSenderAccountId')->willReturn($accountId);
        $subject->method('isSenderIdentified')->willReturn($identified);
        $subject->method('isSenderIrcOperator')->willReturn($ircOperator);

        return $subject;
    }
}
