<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messenger;

use App\Infrastructure\Messenger\ConsumerProcessManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConsumerProcessManager::class)]
final class ConsumerProcessManagerTest extends TestCase
{
    #[Test]
    public function isRunningReturnsFalseWhenProcessNotStarted(): void
    {
        $manager = new ConsumerProcessManager('/nonexistent');

        self::assertFalse($manager->isRunning());
    }

    #[Test]
    public function stopWhenNotStartedDoesNothing(): void
    {
        $manager = new ConsumerProcessManager('/nonexistent');

        $manager->stop();

        self::assertFalse($manager->isRunning());
    }

    #[Test]
    public function startIsIdempotentWhenProcessAlreadyStarted(): void
    {
        $script = $this->createTemporaryConsoleScript();
        try {
            $manager = new ConsumerProcessManager($script);

            $manager->start();
            $manager->start();

            $manager->stop();
            self::assertFalse($manager->isRunning());
        } finally {
            @unlink($script);
        }
    }

    #[Test]
    public function isRunningReturnsTrueWhileProcessIsAliveThenFalseAfterStop(): void
    {
        $script = $this->createTemporaryConsoleScriptWithSleep(2);
        try {
            $manager = new ConsumerProcessManager($script);

            $manager->start();

            self::assertTrue($manager->isRunning());
            $manager->stop();
            self::assertFalse($manager->isRunning());
        } finally {
            @unlink($script);
        }
    }

    private function createTemporaryConsoleScript(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ares_console_');
        if (false === $tmp) {
            self::fail('Could not create temp file');
        }
        $path = $tmp . '.php';
        rename($tmp, $path);
        file_put_contents($path, "<?php\ndeclare(strict_types=1);\nexit(0);\n");

        return $path;
    }

    private function createTemporaryConsoleScriptWithSleep(int $seconds): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ares_console_');
        if (false === $tmp) {
            self::fail('Could not create temp file');
        }
        $path = $tmp . '.php';
        rename($tmp, $path);
        file_put_contents($path, "<?php\ndeclare(strict_types=1);\nsleep(" . $seconds . ');' . "\nexit(0);\n");

        return $path;
    }
}
