<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\Out\NickServ;

use App\ChanServ\Adapter\Out\NickServ\FounderRescueTokenValidatorAdapter;
use App\ChanServ\Application\UseCase\TransferFounder\FounderRescueTokenOutcome;
use App\ChanServ\Application\UseCase\TransferFounder\FounderRescueTokenResult;
use App\NickServ\Application\Port\In\RescueTokenValidation;
use App\NickServ\Application\Port\In\RescueTokenValidationOutcome;
use App\NickServ\Application\Port\In\RescueTokenValidationResult;
use App\NickServ\Application\Port\In\RescueTokenValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(FounderRescueTokenValidatorAdapter::class)]
#[CoversClass(FounderRescueTokenResult::class)]
final class FounderRescueTokenValidatorAdapterTest extends TestCase
{
    /** @return iterable<string, array{RescueTokenValidationOutcome, FounderRescueTokenOutcome}> */
    public static function outcomes(): iterable
    {
        yield 'valid' => [RescueTokenValidationOutcome::Valid, FounderRescueTokenOutcome::Valid];
        yield 'invalid' => [RescueTokenValidationOutcome::Invalid, FounderRescueTokenOutcome::Invalid];
        yield 'unavailable' => [RescueTokenValidationOutcome::Unavailable, FounderRescueTokenOutcome::Unavailable];
        yield 'throttled' => [RescueTokenValidationOutcome::Throttled, FounderRescueTokenOutcome::Throttled];
    }

    #[DataProvider('outcomes')]
    public function testMapsOnlySemanticOutcomeWithoutExposingCredential(RescueTokenValidationOutcome $upstream, FounderRescueTokenOutcome $expected): void
    {
        $validator = $this->createMock(RescueTokenValidator::class);
        $validator->expects(self::once())->method('validate')->with(self::callback(static fn (RescueTokenValidation $input): bool => 10 === $input->accountId && 'secret-token' === $input->token && 'ip:raw' === $input->clientKey))->willReturn(new RescueTokenValidationResult($upstream, 123));
        $result = new FounderRescueTokenValidatorAdapter($validator)->validate(10, 'secret-token', 'ip:raw');
        self::assertSame($expected, $result->outcome);
        self::assertSame(123, $result->retryAfterSeconds);
        self::assertStringNotContainsString('secret-token', serialize($result));
    }
}
