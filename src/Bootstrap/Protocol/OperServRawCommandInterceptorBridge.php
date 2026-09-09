<?php

declare(strict_types=1);

namespace App\Bootstrap\Protocol;

use App\Irc\Adapter\Protocol\RawCommandInterception;
use App\Irc\Adapter\Protocol\RawCommandInterceptionFailure;
use App\Irc\Adapter\Protocol\RawCommandInterceptionOutcome;
use App\Irc\Adapter\Protocol\RawCommandInterceptorInterface;
use App\OperServ\Adapter\In\Irc\Command\ProtocolRawCommandInterceptorInterface;
use App\OperServ\Adapter\In\Irc\Command\RawCommandExecutionOutcome;
use App\OperServ\Adapter\In\Irc\Command\RawCommandExecutionResult;

/** Composition bridge between OperServ's RAW adapter and the selected IRC protocol. */
final readonly class OperServRawCommandInterceptorBridge implements ProtocolRawCommandInterceptorInterface
{
    public function __construct(private RawCommandInterceptorInterface $interceptor) {}

    public function intercept(array $arguments): ?RawCommandExecutionResult
    {
        $interception = $this->interceptor->intercept($arguments);

        return match ($interception->outcome) {
            RawCommandInterceptionOutcome::NotHandled => null,
            RawCommandInterceptionOutcome::Executed => RawCommandExecutionResult::executed(
                $interception->operation ?? 'UNKNOWN',
                true,
            ),
            RawCommandInterceptionOutcome::Rejected => $this->rejection($interception),
        };
    }

    private function rejection(RawCommandInterception $interception): RawCommandExecutionResult
    {
        $outcome = match ($interception->failure) {
            RawCommandInterceptionFailure::TargetInvalid => RawCommandExecutionOutcome::TargetInvalid,
            RawCommandInterceptionFailure::SyntaxInvalid => RawCommandExecutionOutcome::SyntaxInvalid,
            RawCommandInterceptionFailure::Unsupported => RawCommandExecutionOutcome::Unsupported,
            RawCommandInterceptionFailure::ResourceTypeInvalid => RawCommandExecutionOutcome::ResourceTypeInvalid,
            RawCommandInterceptionFailure::ResourceIdentifierInvalid => RawCommandExecutionOutcome::ResourceIdentifierInvalid,
            RawCommandInterceptionFailure::ValueInvalid => RawCommandExecutionOutcome::ValueInvalid,
            RawCommandInterceptionFailure::Rejected, null => RawCommandExecutionOutcome::Failed,
        };

        return RawCommandExecutionResult::rejected(
            $outcome,
            $interception->resourceType,
            $interception->resourceIdentifier,
        );
    }
}
