<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Protocol\UnrealUdb;

use App\Irc\Adapter\Protocol\RawCommandInterception;
use App\Irc\Adapter\Protocol\RawCommandInterceptionFailure;
use App\Irc\Adapter\Protocol\RawCommandInterceptorInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandHandlerInterface;
use App\Irc\Adapter\Protocol\UnrealUdb\Wire\UdbRawCommandResult;

use function array_slice;
use function count;
use function implode;
use function in_array;
use function is_string;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

/** Owns the UDB-specific grammar accepted through OperServ's privileged RAW command. */
final readonly class UnrealUdbRawCommandInterceptor implements RawCommandInterceptorInterface
{
    public function __construct(private UdbRawCommandHandlerInterface $handler) {}

    public function intercept(array $arguments): RawCommandInterception
    {
        if (count($arguments) < 3 || 'DB' !== strtoupper($arguments[0])) {
            return RawCommandInterception::notHandled();
        }

        $subcommand = strtoupper($arguments[2]);
        if (!in_array($subcommand, ['INS', 'DEL', 'DRP', 'OPT'], true)) {
            return RawCommandInterception::notHandled();
        }
        if ('*' !== $arguments[1]) {
            return RawCommandInterception::rejected(
                RawCommandInterceptionFailure::TargetInvalid,
                resourceIdentifier: $arguments[1],
            );
        }
        if ('INS' === $subcommand) {
            if (count($arguments) < 5 || '' === trim($arguments[3])) {
                return RawCommandInterception::rejected(RawCommandInterceptionFailure::SyntaxInvalid);
            }

            return $this->map(
                $this->handler->ins($arguments[3], $this->decodeValue(implode(' ', array_slice($arguments, 4)))),
                'DB INS',
            );
        }
        if ('DEL' === $subcommand) {
            if (4 !== count($arguments) || '' === trim($arguments[3])) {
                return RawCommandInterception::rejected(RawCommandInterceptionFailure::SyntaxInvalid);
            }

            return $this->map($this->handler->del($arguments[3]), 'DB DEL');
        }

        return RawCommandInterception::rejected(RawCommandInterceptionFailure::Unsupported);
    }

    private function map(UdbRawCommandResult $result, string $operation): RawCommandInterception
    {
        if ($result->success) {
            return RawCommandInterception::executed($operation);
        }

        return match ($result->errorKey) {
            'raw.udb.invalid_block' => RawCommandInterception::rejected(
                RawCommandInterceptionFailure::ResourceTypeInvalid,
                resourceType: $this->safeString($result->errorParams['%block%'] ?? null),
            ),
            'raw.udb.invalid_path' => RawCommandInterception::rejected(
                RawCommandInterceptionFailure::ResourceIdentifierInvalid,
                resourceIdentifier: $this->safeString($result->errorParams['%path%'] ?? null),
            ),
            'raw.udb.invalid_value' => RawCommandInterception::rejected(
                RawCommandInterceptionFailure::ValueInvalid,
                resourceIdentifier: $this->safeString($result->errorParams['%path%'] ?? null),
            ),
            default => RawCommandInterception::rejected(RawCommandInterceptionFailure::Rejected),
        };
    }

    private function decodeValue(string $value): string
    {
        if (str_starts_with($value, ':')) {
            $value = substr($value, 1);
        }

        return strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')
            ? substr($value, 1, -1)
            : $value;
    }

    private function safeString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
