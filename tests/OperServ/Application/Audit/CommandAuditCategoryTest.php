<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Application\Audit;

use App\OperServ\Application\Port\In\Audit\CommandAuditCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CommandAuditCategory::class)]
final class CommandAuditCategoryTest extends TestCase
{
    /** @return iterable<array{CommandAuditCategory, string}> */
    public static function categoryProvider(): iterable
    {
        yield [CommandAuditCategory::OperatorAction, 'operator_action'];
        yield [CommandAuditCategory::RootAdministration, 'root_administration'];
        yield [CommandAuditCategory::ResourceOverride, 'resource_override'];
        yield [CommandAuditCategory::SystemAction, 'system_action'];
    }

    #[DataProvider('categoryProvider')]
    #[Test]
    public function exposesStableSemanticValues(CommandAuditCategory $category, string $value): void
    {
        self::assertSame($value, $category->value);
    }
}
