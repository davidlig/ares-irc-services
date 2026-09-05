<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

use function class_exists;
use function enum_exists;
use function file_get_contents;
use function in_array;
use function interface_exists;
use function is_array;
use function ltrim;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function token_get_all;
use function trait_exists;
use function trim;

use const T_CONSTANT_ENCAPSED_STRING;
use const T_USE;

#[CoversNothing]
final class LayerDependencyTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    #[Test]
    public function applicationDoesNotImportInfrastructureOrFrameworks(): void
    {
        $violations = [];

        foreach (self::importsUnder('src/Application') as $import) {
            if (
                str_starts_with($import['name'], 'App\\Infrastructure\\')
                || str_starts_with($import['name'], 'Symfony\\')
                || str_starts_with($import['name'], 'Doctrine\\')
            ) {
                $violations[] = self::describe($import);
            }
        }

        self::assertSame([], $violations, "Application has forbidden dependencies:\n" . implode("\n", $violations));
    }

    #[Test]
    public function domainOnlyImportsDomainOrNativePhpExceptForDocumentedDebt(): void
    {
        $violations = [];

        foreach (self::importsUnder('src/Domain') as $import) {
            if (str_starts_with($import['name'], 'App\\Domain\\') || self::isNativePhpSymbol($import['name'])) {
                continue;
            }

            $violations[] = self::describe($import);
        }

        self::assertSame([], $violations, "Domain has forbidden or external dependencies:\n" . implode("\n", $violations));
    }

    #[Test]
    public function uiDoesNotImportDomainOrInfrastructureExceptForDocumentedDebt(): void
    {
        $violations = [];

        foreach (self::importsUnder('src/UI') as $import) {
            if (!str_starts_with($import['name'], 'App\\Domain\\') && !str_starts_with($import['name'], 'App\\Infrastructure\\')) {
                continue;
            }

            $violations[] = self::describe($import);
        }

        self::assertSame([], $violations, "UI has new Domain or Infrastructure dependencies:\n" . implode("\n", $violations));
    }

    #[Test]
    public function sharedCodeDoesNotReferenceConcreteProtocolNames(): void
    {
        $protocolNames = self::protocolNames();
        $violations = [];

        foreach (self::sharedPhpFiles() as $file) {
            foreach (token_get_all((string) file_get_contents(self::ROOT . '/' . $file)) as $token) {
                if (!is_array($token) || T_CONSTANT_ENCAPSED_STRING !== $token[0]) {
                    continue;
                }

                $literal = strtolower(trim($token[1], "'\""));
                if (in_array($literal, $protocolNames, true)) {
                    $violations[] = $file . ':' . $token[2] . ' references protocol ' . $literal;
                }
            }
        }

        self::assertSame([], $violations, "Shared code references concrete protocols:\n" . implode("\n", $violations));
    }

    /**
     * @return list<array{file: string, line: int, name: string}>
     */
    private static function importsUnder(string $path): array
    {
        $imports = [];

        foreach (self::phpFiles($path) as $file) {
            $tokens = token_get_all((string) file_get_contents(self::ROOT . '/' . $file));
            $depth = 0;

            foreach ($tokens as $index => $token) {
                if ('{' === $token) {
                    ++$depth;
                    continue;
                }
                if ('}' === $token) {
                    --$depth;
                    continue;
                }
                if (!is_array($token) || T_USE !== $token[0] || 0 !== $depth) {
                    continue;
                }

                $statement = '';
                for ($cursor = $index + 1; isset($tokens[$cursor]) && ';' !== $tokens[$cursor]; ++$cursor) {
                    $statement .= is_array($tokens[$cursor]) ? $tokens[$cursor][1] : $tokens[$cursor];
                }

                $statement = trim($statement);
                if (str_starts_with($statement, 'function ') || str_starts_with($statement, 'const ')) {
                    continue;
                }

                self::assertStringNotContainsString('{', $statement, $file . ' uses a grouped import, which the architecture scanner does not accept');

                foreach (explode(',', $statement) as $name) {
                    $name = trim(explode(' as ', trim($name), 2)[0]);
                    $imports[] = ['file' => $file, 'line' => $token[2], 'name' => ltrim($name, '\\')];
                }
            }
        }

        return $imports;
    }

    private static function isNativePhpSymbol(string $name): bool
    {
        if (!class_exists($name) && !interface_exists($name) && !trait_exists($name) && !enum_exists($name)) {
            return false;
        }

        return new ReflectionClass($name)->isInternal();
    }

    /** @param array{file: string, line: int, name: string} $import */
    private static function describe(array $import): string
    {
        return $import['file'] . ':' . $import['line'] . ' imports ' . $import['name'];
    }

    /** @return list<string> */
    private static function sharedPhpFiles(): array
    {
        $paths = [
            'src/Domain',
            'src/Application/Port',
            'src/Application/ApplicationPort',
            'src/Infrastructure/IRC/Runtime',
            'src/Infrastructure/IRC/Protocol/AbstractProtocolHandler.php',
            'src/Infrastructure/IRC/Protocol/NullChannelModeSupport.php',
            'src/Infrastructure/IRC/Protocol/ProtocolHandlerRegistry.php',
            'src/Infrastructure/IRC/Protocol/ProtocolModuleRegistry.php',
            'src/Infrastructure/IRC/Protocol/UnrealFamily',
        ];
        $files = [];

        foreach ($paths as $path) {
            $files = [...$files, ...self::phpFiles($path)];
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private static function protocolNames(): array
    {
        $directory = new RecursiveDirectoryIterator(self::ROOT . '/src/Infrastructure/IRC/Protocol');
        $names = [];

        foreach ($directory as $entry) {
            if (!$entry->isDir() || str_starts_with($entry->getFilename(), '.') || 'UnrealFamily' === $entry->getFilename()) {
                continue;
            }

            $names[] = strtolower($entry->getFilename());
        }

        sort($names);

        return $names;
    }

    /** @return list<string> */
    private static function phpFiles(string $path): array
    {
        $absolutePath = self::ROOT . '/' . $path;
        if (is_file($absolutePath)) {
            return [$path];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolutePath));

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            if ($entry->isFile() && str_ends_with($entry->getFilename(), '.php')) {
                $files[] = substr($entry->getPathname(), strlen(self::ROOT) + 1);
            }
        }

        sort($files);

        return $files;
    }
}
