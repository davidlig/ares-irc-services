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

use function array_unique;
use function class_exists;
use function dirname;
use function enum_exists;
use function file_get_contents;
use function in_array;
use function interface_exists;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function ltrim;
use function preg_match;
use function scandir;
use function sort;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function token_get_all;
use function trait_exists;
use function trim;

use const JSON_THROW_ON_ERROR;
use const PATHINFO_EXTENSION;
use const T_ABSTRACT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_STRING;
use const T_TRAIT;
use const T_USE;

#[CoversNothing]
final class LayerDependencyTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../..';

    /** @var list<string> */
    private const array BOUNDED_CONTEXTS = ['Irc', 'NickServ', 'ChanServ', 'MemoServ', 'OperServ'];

    /** @var list<string> */
    private const array LEGACY_ROOTS = ['src/Application', 'src/Domain', 'src/Infrastructure', 'src/UI', 'src/Kernel.php'];

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

        $finalPaths = ['src/Shared/Application'];
        foreach (self::BOUNDED_CONTEXTS as $context) {
            $finalPaths[] = 'src/' . $context . '/Application';
        }

        foreach ($finalPaths as $path) {
            foreach (self::importsUnder($path) as $import) {
                if (str_starts_with($import['file'], 'src/NickServ/Application/Service/')) {
                    continue;
                }
                if (str_contains($import['name'], '\\Adapter\\') || self::isFrameworkOrInfrastructure($import['name'])) {
                    $violations[] = self::describe($import);
                }
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

        foreach ([...self::BOUNDED_CONTEXTS, 'Shared'] as $context) {
            foreach (self::importsUnder('src/' . $context . '/Domain') as $import) {
                if (
                    str_starts_with($import['name'], 'App\\' . $context . '\\Domain\\')
                    || ('Shared' !== $context && str_starts_with($import['name'], 'App\\Shared\\Domain\\'))
                    || self::isNativePhpSymbol($import['name'])
                ) {
                    continue;
                }

                $violations[] = self::describe($import);
            }
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

    #[Test]
    public function legacyFilesMatchTheExactPhaseOwnedDebtInventory(): void
    {
        $documented = [];

        foreach (self::architectureDebt()['legacy_paths'] as $phase => $files) {
            self::assertSame(1, preg_match('/^0[2-9]-[a-z0-9-]+$/', $phase), 'Every legacy path group must name its owning later phase');

            foreach ($files as $file) {
                $documented[] = $file;
            }
        }

        self::assertSameSize(array_unique($documented), $documented, 'The legacy path inventory contains duplicates');

        $actual = [];
        foreach (self::LEGACY_ROOTS as $path) {
            $actual = [...$actual, ...self::phpFiles($path)];
        }

        sort($actual);
        sort($documented);

        self::assertSame(
            $documented,
            $actual,
            'Legacy PHP paths changed. Remove migrated paths or assign every new exception to its owning phase in architecture-debt.json.'
        );
    }

    #[Test]
    public function phaseEightHasNoLegacyPaths(): void
    {
        self::assertSame(
            [],
            self::architectureDebt()['legacy_paths']['08-unreal-udb'] ?? null,
            'Phase 08 is complete only when every UnrealUdb-owned legacy path has moved to its final owner.',
        );
    }

    #[Test]
    public function newTopologyOnlyUsesTheModeledContextsAndHexagonalLayers(): void
    {
        $allowedRoots = [
            'Application',
            'Bootstrap',
            'ChanServ',
            'Domain',
            'Infrastructure',
            'Irc',
            'Kernel.php',
            'MemoServ',
            'NickServ',
            'OperServ',
            'Shared',
            'UI',
        ];
        $entries = scandir(self::ROOT . '/src');
        self::assertIsArray($entries);

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            self::assertContains($entry, $allowedRoots, 'src/' . $entry . ' is not a modeled bounded context, Shared, Bootstrap, or exact legacy root');
        }

        foreach (self::BOUNDED_CONTEXTS as $context) {
            self::assertOnlyContainsDirectories($context, ['Adapter', 'Application', 'Domain']);
        }

        self::assertOnlyContainsDirectories('Shared', ['Application', 'Domain']);
        self::assertFalse(is_dir(self::ROOT . '/src/Udb'), 'UDB is a protocol concern, not a bounded context');
        self::assertDirectoryDoesNotExist(self::ROOT . '/src/Domain/Udb');
        self::assertDirectoryDoesNotExist(self::ROOT . '/src/Infrastructure/Udb');
        self::assertDirectoryDoesNotExist(self::ROOT . '/src/Infrastructure/IRC/Protocol/UnrealUdb');

        $protocolPath = self::ROOT . '/src/Irc/Adapter/Protocol';
        if (is_dir($protocolPath)) {
            self::assertProtocolRootContainsOnlyNeutralContractsAndImplementations(
                'Irc/Adapter/Protocol',
                ['InspIRCd', 'UnrealStandalone', 'UnrealUdb'],
            );
        }
    }

    #[Test]
    public function phpNamespacesFollowTheirPsr4Paths(): void
    {
        foreach (self::phpFiles('src') as $file) {
            $relativeDirectory = dirname(substr($file, strlen('src/')));
            $expectedNamespace = '.' === $relativeDirectory
                ? 'App'
                : 'App\\' . str_replace('/', '\\', $relativeDirectory);
            $contents = file_get_contents(self::ROOT . '/' . $file);
            self::assertIsString($contents);
            $matches = [];

            self::assertSame(1, preg_match('/^namespace\s+([^;{]+)[;{]/m', $contents, $matches), $file . ' must declare its namespace');
            self::assertSame($expectedNamespace, trim($matches[1]), $file . ' must follow the App\\ PSR-4 path');
        }
    }

    #[Test]
    public function innerLayersDoNotNameConcreteProtocolsExceptForExactPhaseOwnedDebt(): void
    {
        $actual = [];
        $paths = ['src/Application', 'src/Domain'];

        foreach (self::BOUNDED_CONTEXTS as $context) {
            $paths[] = 'src/' . $context . '/Application';
            $paths[] = 'src/' . $context . '/Domain';
        }
        $paths[] = 'src/Shared/Application';
        $paths[] = 'src/Shared/Domain';

        foreach ($paths as $path) {
            foreach (self::phpFiles($path) as $file) {
                if (self::containsConcreteProtocolIdentifier($file)) {
                    $actual[] = $file;
                }
            }
        }

        $documented = [];
        foreach (self::architectureDebt()['protocol_named_inner_files'] as $phase => $files) {
            self::assertSame(1, preg_match('/^0[2-9]-[a-z0-9-]+$/', $phase), 'Every protocol-name exception must name its owning later phase');
            $documented = [...$documented, ...$files];
        }

        sort($actual);
        sort($documented);

        self::assertSame(
            $documented,
            $actual,
            'Service, Irc, and Shared inner layers must not acquire concrete IRCd or UDB types'
        );
    }

    #[Test]
    public function unrealImplementationsNeitherDependOnSiblingsNorShareBehaviorExceptForExactDebt(): void
    {
        $actual = [];
        $paths = [
            'src/Irc/Adapter/Protocol/UnrealStandalone',
            'src/Irc/Adapter/Protocol/UnrealUdb',
        ];

        foreach ($paths as $path) {
            foreach (self::phpFiles($path) as $file) {
                $contents = file_get_contents(self::ROOT . '/' . $file);
                self::assertIsString($contents);

                if (str_contains($file, '/UnrealStandalone/') && str_contains($contents, '\\UnrealUdb\\')) {
                    $actual[] = $file . ':UnrealUdb';
                }
                if (str_contains($file, '/UnrealUdb/') && str_contains($contents, '\\UnrealStandalone\\')) {
                    $actual[] = $file . ':UnrealStandalone';
                }
            }
        }

        $documented = [];
        foreach (self::architectureDebt()['unreal_family_dependencies'] as $phase => $dependencies) {
            self::assertSame('03-protocol-adapters', $phase, 'Shared Unreal behavior belongs only to Phase 03 cleanup');
            $documented = [...$documented, ...$dependencies];
        }

        sort($actual);
        sort($documented);

        self::assertSame($documented, $actual, 'UnrealStandalone and UnrealUdb must evolve independently without a shared behavioral layer');
        self::assertDirectoryDoesNotExist(self::ROOT . '/src/Infrastructure/IRC/Protocol/Unreal');
        self::assertDirectoryDoesNotExist(self::ROOT . '/src/Infrastructure/IRC/Protocol/UnrealFamily');
    }

    #[Test]
    public function neutralProtocolRootContainsNoSharedBehavioralBaseOrTrait(): void
    {
        $path = self::ROOT . '/src/Irc/Adapter/Protocol';
        $entries = scandir($path);
        self::assertIsArray($entries);

        foreach ($entries as $entry) {
            $file = $path . '/' . $entry;
            if (!is_file($file) || 'php' !== pathinfo($file, PATHINFO_EXTENSION)) {
                continue;
            }

            $contents = file_get_contents($file);
            self::assertIsString($contents);
            self::assertDoesNotMatchRegularExpression('/(?:UnrealFamily|UnrealBase|AbstractUnreal)/', $contents, $entry);

            foreach (token_get_all($contents) as $token) {
                if (!is_array($token)) {
                    continue;
                }

                self::assertNotContains($token[0], [T_TRAIT, T_ABSTRACT], $entry . ' must remain a protocol-neutral contract or concrete primitive');
            }
        }
    }

    #[Test]
    public function configuredProtocolSelectionDoesNotLeakFromBootstrap(): void
    {
        $filesAndForbiddenFragments = [
            'src/Irc/Application/Connect/ConnectToServerCommand.php' => ['public string $protocol'],
            'src/Irc/Application/Connect/ConnectToServerHandler.php' => ['$command->protocol'],
            'src/Irc/Adapter/Runtime/IRCClientFactory.php' => ['$protocolName', '->get($protocolName)'],
            'src/Irc/Adapter/Network/ProtocolNetworkStateRouter.php' => ['$adapters', 'getProtocolName()'],
            'src/UI/CLI/ConnectCommand.php' => ["'protocol',", "getOption('protocol')"],
        ];

        foreach ($filesAndForbiddenFragments as $file => $fragments) {
            $contents = file_get_contents(self::ROOT . '/' . $file);
            self::assertIsString($contents);

            foreach ($fragments as $fragment) {
                self::assertStringNotContainsString($fragment, $contents, $file . ' must not select a concrete protocol');
            }
        }
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

    private static function isFrameworkOrInfrastructure(string $name): bool
    {
        foreach (['App\\Infrastructure\\', 'Amp\\', 'Doctrine\\', 'Psr\\', 'Revolt\\', 'Symfony\\'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @param array{file: string, line: int, name: string} $import */
    private static function describe(array $import): string
    {
        return $import['file'] . ':' . $import['line'] . ' imports ' . $import['name'];
    }

    /**
     * @return array{
     *     legacy_paths: array<string, list<string>>,
     *     protocol_named_inner_files: array<string, list<string>>,
     *     unreal_family_dependencies: array<string, list<string>>
     * }
     */
    private static function architectureDebt(): array
    {
        $contents = file_get_contents(self::ROOT . '/architecture-debt.json');
        self::assertIsString($contents);
        $debt = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($debt);

        self::assertArrayHasKey('legacy_paths', $debt);
        self::assertArrayHasKey('protocol_named_inner_files', $debt);
        self::assertArrayHasKey('unreal_family_dependencies', $debt);

        return [
            'legacy_paths' => self::stringListMap($debt['legacy_paths']),
            'protocol_named_inner_files' => self::stringListMap($debt['protocol_named_inner_files']),
            'unreal_family_dependencies' => self::stringListMap($debt['unreal_family_dependencies']),
        ];
    }

    /** @return array<string, list<string>> */
    private static function stringListMap(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];

        foreach ($value as $key => $items) {
            self::assertIsString($key);
            self::assertIsArray($items);
            $result[$key] = [];

            foreach ($items as $item) {
                self::assertIsString($item);
                $result[$key][] = $item;
            }
        }

        return $result;
    }

    /** @param list<string> $allowed */
    private static function assertOnlyContainsDirectories(string $relativePath, array $allowed): void
    {
        $absolutePath = self::ROOT . '/src/' . $relativePath;
        if (!is_dir($absolutePath)) {
            return;
        }

        $entries = scandir($absolutePath);
        self::assertIsArray($entries);

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            self::assertTrue(is_dir($absolutePath . '/' . $entry), 'Only modeled layer directories may live directly under src/' . $relativePath);
            self::assertContains($entry, $allowed, 'Unexpected layer src/' . $relativePath . '/' . $entry);
        }
    }

    /** @param list<string> $allowedDirectories */
    private static function assertProtocolRootContainsOnlyNeutralContractsAndImplementations(
        string $relativePath,
        array $allowedDirectories,
    ): void {
        $absolutePath = self::ROOT . '/src/' . $relativePath;
        $entries = scandir($absolutePath);
        self::assertIsArray($entries);

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            if (is_dir($absolutePath . '/' . $entry)) {
                self::assertContains($entry, $allowedDirectories, 'Unexpected protocol implementation src/' . $relativePath . '/' . $entry);

                continue;
            }

            self::assertSame('php', pathinfo($entry, PATHINFO_EXTENSION), 'Only protocol-neutral PHP contracts may live directly under src/' . $relativePath);
            self::assertFalse(
                self::containsConcreteProtocolIdentifier($relativePath . '/' . $entry),
                'Concrete protocol code must live in its named implementation directory: src/' . $relativePath . '/' . $entry,
            );
        }
    }

    private static function containsConcreteProtocolIdentifier(string $file): bool
    {
        $identifierTokenTypes = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

        foreach (token_get_all((string) file_get_contents(self::ROOT . '/' . $file)) as $token) {
            if (!is_array($token) || !in_array($token[0], $identifierTokenTypes, true)) {
                continue;
            }

            if (1 === preg_match('/(?:InspIRCd|UnrealStandalone|UnrealUdb|Udb)/', $token[1])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function sharedPhpFiles(): array
    {
        $paths = [
            'src/Domain',
            'src/Application/Port',
            'src/Application/Shared',
            'src/Irc/Domain',
            'src/Irc/Application',
            'src/Shared',
            'src/Infrastructure/IRC/Runtime',
            'src/Irc/Adapter/Protocol/IRCMessage.php',
            'src/Irc/Adapter/Protocol/MessageDirection.php',
            'src/Irc/Adapter/Protocol/NetworkStateAdapterInterface.php',
            'src/Irc/Adapter/Protocol/NullChannelModeSupport.php',
            'src/Irc/Adapter/Protocol/ProtocolHandlerInterface.php',
            'src/Irc/Adapter/Runtime/ProtocolRuntimeModuleInterface.php',
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
        $names = [];

        foreach (['src/Infrastructure/IRC/Protocol', 'src/Irc/Adapter/Protocol'] as $path) {
            $absolutePath = self::ROOT . '/' . $path;
            if (!is_dir($absolutePath)) {
                continue;
            }

            $directory = new RecursiveDirectoryIterator($absolutePath);
            foreach ($directory as $entry) {
                if (!$entry instanceof SplFileInfo) {
                    continue;
                }

                if (!$entry->isDir() || str_starts_with($entry->getFilename(), '.')) {
                    continue;
                }

                $names[] = strtolower($entry->getFilename());
            }
        }

        $names = array_unique($names);
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
        if (!is_dir($absolutePath)) {
            return [];
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
