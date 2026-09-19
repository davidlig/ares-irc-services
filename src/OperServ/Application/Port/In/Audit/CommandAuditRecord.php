<?php

declare(strict_types=1);

namespace App\OperServ\Application\Port\In\Audit;

use App\Shared\Application\Audit\SafeAuditMetadata;
use DateTimeImmutable;
use InvalidArgumentException;

use function sprintf;
use function trim;

final readonly class CommandAuditRecord
{
    /** @var array<string, bool|float|int|string|null> */
    public array $metadata;

    /**
     * @param array<array-key, mixed> $metadata
     */
    public function __construct(
        public CommandAuditCategory $category,
        public string $service,
        public string $actor,
        public string $operation,
        public DateTimeImmutable $occurredAt,
        public ?string $target = null,
        public ?string $reason = null,
        public ?string $permission = null,
        public ?string $targetHost = null,
        public ?string $targetIp = null,
        array $metadata = [],
    ) {
        self::assertNotBlank($service, 'service');
        self::assertNotBlank($actor, 'actor');
        self::assertNotBlank($operation, 'operation');
        $this->metadata = SafeAuditMetadata::validate($metadata);
    }

    private static function assertNotBlank(string $value, string $field): void
    {
        if ('' === trim($value)) {
            throw new InvalidArgumentException(sprintf('Command audit %s cannot be blank.', $field));
        }
    }
}
