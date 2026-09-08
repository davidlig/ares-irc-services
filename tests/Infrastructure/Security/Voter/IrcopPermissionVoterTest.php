<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security\Voter;

use App\Application\Security\IrcopContextInterface;
use App\Infrastructure\Security\Voter\IrcopPermissionVoter;
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

#[CoversClass(IrcopPermissionVoter::class)]
final class IrcopPermissionVoterTest extends TestCase
{
    #[Test]
    public function delegatesPermissionDecisionUsingSubjectFacts(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('permission')
            ->with(new OperatorActor('Oper', 7, true, true), 'operserv.kill')
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RolePermission));

        self::assertSame(VoterInterface::ACCESS_GRANTED, new IrcopPermissionVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->authorizationSubject('Oper', 7, true, true),
            ['operserv.kill'],
        ));
    }

    #[Test]
    public function returnsDeniedDecisionFromAuthorizationBoundary(): void
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('permission')->willReturn(AuthorizationDecision::denied());

        self::assertSame(VoterInterface::ACCESS_DENIED, new IrcopPermissionVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->authorizationSubject('Oper', null, false, true),
            ['operserv.kill'],
        ));
    }

    #[Test]
    public function deniesWithoutNicknameBeforeCallingAuthorization(): void
    {
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::never())->method('permission');

        self::assertSame(VoterInterface::ACCESS_DENIED, new IrcopPermissionVoter($authorization)->vote(
            $this->createStub(TokenInterface::class),
            $this->authorizationSubject(null, null, false, false),
            ['operserv.kill'],
        ));
    }

    #[Test]
    public function supportsOnlyDotNotationAndAuthorizationSubjects(): void
    {
        $voter = new IrcopPermissionVoter($this->createStub(OperatorAuthorizationQuery::class));
        $token = $this->createStub(TokenInterface::class);
        $context = $this->authorizationSubject('Oper', 7, true, true);

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, $context, ['NICKSERV_DROP']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($token, new stdClass(), ['operserv.kill']));
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
