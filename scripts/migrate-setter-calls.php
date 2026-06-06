#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migrates caller code from removed Domain entity setter/getter methods.
 */

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

require_once __DIR__ . '/../vendor/autoload.php';

// Setters that were removed: old method name => new property name
$removedSetters = [
    'setNoExpire' => 'noExpire',
    'setDescription' => 'description',
    'setReason' => 'reason',
    'setForcedVhostPattern' => 'forcedVhostPattern',
    'updateExpiry' => 'expiresAt',
    'updateReason' => 'reason',
];

$parser = new ParserFactory()->createForHostVersion();
$printer = new PrettyPrinter\Standard();

$directories = [
    __DIR__ . '/../src',
    __DIR__ . '/../tests',
];

$filesModified = 0;
$totalReplacements = 0;

foreach ($directories as $dir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || 'php' !== $file->getExtension()) {
            continue;
        }

        $filePath = $file->getRealPath();
        $code = file_get_contents($filePath);
        if (false === $code) {
            continue;
        }

        try {
            $ast = $parser->parse($code);
        } catch (Throwable) {
            continue;
        }

        if (null === $ast) {
            continue;
        }

        $replaced = 0;

        $visitor = new class($removedSetters, $replaced) extends NodeVisitorAbstract {
            public function __construct(
                private array $removedSetters,
                private int &$replaced,
            ) {}

            public function leaveNode(Node $node): mixed
            {
                if ($node instanceof MethodCall) {
                    $methodName = $node->name instanceof Identifier ? $node->name->name : null;
                    if (null === $methodName) {
                        return null;
                    }

                    if (isset($this->removedSetters[$methodName])) {
                        $propName = $this->removedSetters[$methodName];

                        if ([] === $node->args) {
                            // No args — can't convert setter without value
                            return null;
                        }

                        $value = $node->args[0]->value;
                        ++$this->replaced;

                        return new Assign(
                            new PropertyFetch($node->var, $propName),
                            $value,
                        );
                    }
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new PhpParser\NodeVisitor\ParentConnectingVisitor());
        $traverser->addVisitor($visitor);
        $newAst = $traverser->traverse($ast);

        if ($replaced > 0) {
            $newCode = $printer->prettyPrintFile($newAst);
            file_put_contents($filePath, $newCode);
            ++$filesModified;
            $totalReplacements += $replaced;
            echo "  {$filePath}: {$replaced} replacements\n";
        }
    }
}

echo "\nDone: {$totalReplacements} setter replacements in {$filesModified} files.\n";
