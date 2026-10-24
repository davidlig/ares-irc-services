<?php

declare(strict_types=1);

namespace App\Tests\Bootstrap;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class GeneratedArtifactsIgnoreTest extends TestCase
{
    #[Test]
    public function onlyPythonGeneratedArtifactsAreIgnored(): void
    {
        foreach (['__pycache__/fixture.pyc' => true, 'scripts/deploy/__pycache__/fixture.pyc' => true, 'scripts/fixture.pyc' => true, 'scripts/fixture.py' => false, 'tests/fixtures/expected.txt' => false, 'tmp/source.php' => false] as $path => $ignored) {
            $pipes = [];
            $process = proc_open(['git', '-c', 'core.excludesFile=/dev/null', 'check-ignore', '--no-index', '-q', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2));
            self::assertIsResource($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame($ignored ? 0 : 1, proc_close($process), $path);
        }
    }
}
