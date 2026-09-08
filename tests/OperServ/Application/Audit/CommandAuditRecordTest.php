<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\Audit;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use App\OperServ\Application\Port\In\Audit\CommandAuditRecord;
use App\Shared\Application\Audit\SafeAuditMetadata;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;

#[CoversClass(CommandAuditRecord::class)]
#[CoversClass(SafeAuditMetadata::class)]
final class CommandAuditRecordTest extends TestCase
{
    #[Test]
    public function safeMetadataValidatorCannotBeConstructedFromApplicationCode(): void
    {
        $reflection = new ReflectionClass(SafeAuditMetadata::class);
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor = $reflection->getConstructor();
        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        $constructor->invoke($instance);
    }

    #[Test]
    public function preservesSafeSemanticData(): void
    {
        $occurredAt = new DateTimeImmutable('2026-09-08T10:15:00+00:00');
        $record = new CommandAuditRecord(
            category: CommandAuditCategory::OperatorAction,
            service: 'operserv',
            actor: 'Admin',
            operation: 'operserv.gline.add',
            occurredAt: $occurredAt,
            permission: 'operserv.gline',
            target: '*@example.test',
            targetHost: 'ident@example.test',
            targetIp: '192.0.2.1',
            reason: 'abuse',
            metadata: ['duration' => '1h', 'count' => 2, 'permanent' => false, 'score' => 1.5, 'note' => null],
        );

        self::assertSame(CommandAuditCategory::OperatorAction, $record->category);
        self::assertSame('operserv', $record->service);
        self::assertSame('Admin', $record->actor);
        self::assertSame('operserv.gline.add', $record->operation);
        self::assertSame($occurredAt, $record->occurredAt);
        self::assertSame('operserv.gline', $record->permission);
        self::assertSame('*@example.test', $record->target);
        self::assertSame('ident@example.test', $record->targetHost);
        self::assertSame('192.0.2.1', $record->targetIp);
        self::assertSame('abuse', $record->reason);
        self::assertSame(['duration' => '1h', 'count' => 2, 'permanent' => false, 'score' => 1.5, 'note' => null], $record->metadata);
    }

    /** @return iterable<string, array{string, string}> */
    public static function blankRequiredFieldProvider(): iterable
    {
        yield 'service' => ['service', '  '];
        yield 'actor' => ['actor', ''];
        yield 'operation' => ['operation', "\t"];
    }

    #[DataProvider('blankRequiredFieldProvider')]
    #[Test]
    public function rejectsBlankRequiredFields(string $field, string $value): void
    {
        $arguments = [
            'category' => CommandAuditCategory::OperatorAction,
            'service' => 'operserv',
            'actor' => 'Admin',
            'operation' => 'operserv.kill',
            'occurredAt' => new DateTimeImmutable('2026-09-08T10:15:00+00:00'),
        ];
        $arguments[$field] = $value;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Command audit ' . $field . ' cannot be blank.');

        new ReflectionClass(CommandAuditRecord::class)->newInstanceArgs($arguments);
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveMetadataKeyProvider(): iterable
    {
        yield 'password' => ['password'];
        yield 'separated password' => ['new_password_hash'];
        yield 'token' => ['access-token'];
        yield 'secret' => ['clientSecret'];
        yield 'credential' => ['AUTH_CREDENTIALS'];
    }

    #[DataProvider('sensitiveMetadataKeyProvider')]
    #[Test]
    public function rejectsSensitiveMetadataKeys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sensitive command audit metadata key');

        $this->record([$key => 'redacted']);
    }

    /** @return iterable<string, array{string}> */
    public static function rawPayloadMetadataKeyProvider(): iterable
    {
        yield 'args' => ['args'];
        yield 'arguments' => ['arguments'];
        yield 'command args' => ['command_args'];
        yield 'command object' => ['command-object'];
        yield 'payload' => ['payload'];
        yield 'raw args' => ['raw_args'];
        yield 'raw arguments' => ['rawArguments'];
        yield 'raw line' => ['raw-line'];
    }

    #[DataProvider('rawPayloadMetadataKeyProvider')]
    #[Test]
    public function rejectsRawPayloadMetadataKeys(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Raw command audit metadata key');

        $this->record([$key => 'RAW PASS hunter2']);
    }

    #[Test]
    public function rejectsEmptyMetadataKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('metadata keys must be non-empty strings');

        $this->record(['  ' => 'value']);
    }

    #[Test]
    public function rejectsNonStringMetadataKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('metadata keys must be non-empty strings');

        $this->recordThroughReflection([0 => 'value']);
    }

    #[Test]
    public function rejectsNonScalarMetadataValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be scalar or null');

        $this->recordThroughReflection(['nested' => new stdClass()]);
    }

    /** @return iterable<string, array{string}> */
    public static function sensitiveOptionProvider(): iterable
    {
        yield 'password' => ['PASSWORD'];
        yield 'token' => ['reset-token'];
        yield 'secret' => ['Client Secret'];
        yield 'credential' => ['credentials'];
    }

    #[DataProvider('sensitiveOptionProvider')]
    #[Test]
    public function rejectsValuesForSensitiveOptions(string $option): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Sensitive command audit options cannot include a value.');

        $this->record(['Option' => $option, 'new_value' => 'must-not-leak']);
    }

    #[Test]
    public function allowsRedactedSensitiveOptionAndSafeOptionValue(): void
    {
        $redacted = $this->record(['option' => 'PASSWORD', 'value' => null]);
        $safe = $this->record(['option' => 'LANGUAGE', 'value' => 'es']);

        self::assertSame(['option' => 'PASSWORD', 'value' => null], $redacted->metadata);
        self::assertSame(['option' => 'LANGUAGE', 'value' => 'es'], $safe->metadata);
    }

    /** @param array<string, bool|float|int|string|null> $metadata */
    private function record(array $metadata): CommandAuditRecord
    {
        return new CommandAuditRecord(
            CommandAuditCategory::OperatorAction,
            'operserv',
            'Admin',
            'operserv.raw',
            new DateTimeImmutable('2026-09-08T10:15:00+00:00'),
            metadata: $metadata,
        );
    }

    /** @param array<mixed> $metadata */
    private function recordThroughReflection(array $metadata): void
    {
        new ReflectionClass(CommandAuditRecord::class)->newInstanceArgs([
            'category' => CommandAuditCategory::OperatorAction,
            'service' => 'operserv',
            'actor' => 'Admin',
            'operation' => 'operserv.raw',
            'occurredAt' => new DateTimeImmutable('2026-09-08T10:15:00+00:00'),
            'metadata' => $metadata,
        ]);
    }
}
