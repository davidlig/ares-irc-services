<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap\Persistence;

use Doctrine\DBAL\ParameterType;
use Psr\Log\AbstractLogger;
use Stringable;

use function assert;
use function is_array;
use function is_string;

/** Captures driver-level SQL only during the explicitly bounded plan assertions. */
final class QueryCaptureLogger extends AbstractLogger
{
    public bool $enabled = false;

    /** @var list<array{sql: string, params: list<mixed>, types: list<ParameterType>}> */
    public array $queries = [];

    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        if (!$this->enabled || !isset($context['sql'])) {
            return;
        }

        $sql = $context['sql'];
        $params = $context['params'] ?? [];
        $types = $context['types'] ?? [];
        assert(is_string($sql));
        assert(is_array($params));
        assert(is_array($types));
        foreach ($types as $type) {
            assert($type instanceof ParameterType);
        }
        $this->queries[] = ['sql' => $sql, 'params' => array_values($params), 'types' => array_values($types)];
    }
}
