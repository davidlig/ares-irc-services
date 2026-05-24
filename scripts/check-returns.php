#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

require_once __DIR__ . '/../vendor/autoload.php';

$maxReturns = 3;

$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if (str_starts_with($arg, '--max=')) {
        $maxReturns = (int) substr($arg, 6);
    }
}

$parser = (new ParserFactory())->createForHostVersion();

$violations = [];
$filesChecked = 0;

$srcDir = __DIR__ . '/../src';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if (!$file->isFile() || 'php' !== $file->getExtension()) {
        continue;
    }

    $filePath = $file->getRealPath();
    $code = file_get_contents($filePath);

    if (false === $code || '' === trim($code)) {
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

    $traverser = new NodeTraverser();

    $methodStack = [];
    $methodLines = [];
    $methodReturns = [];
    $classStack = [];
    $closureDepth = 0;

    $visitor = new class($methodStack, $methodLines, $methodReturns, $classStack, $closureDepth) extends NodeVisitorAbstract {
        public function __construct(
            private array &$methodStack,
            private array &$methodLines,
            private array &$methodReturns,
            private array &$classStack,
            private int &$closureDepth,
        ) {
        }

        public function enterNode(Node $node): mixed
        {
            if ($node instanceof Node\Stmt\ClassLike) {
                $this->classStack[] = $node->name->name ?? 'anonymous';

                return null;
            }

            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                ++$this->closureDepth;

                return null;
            }

            if ($node instanceof Node\Stmt\Return_) {
                if (0 === $this->closureDepth && [] !== $this->methodStack) {
                    $top = end($this->methodStack);
                    ++$this->methodReturns[$top];
                }

                return null;
            }

            if ($node instanceof ClassMethod || $node instanceof Function_) {
                $prefix = [] !== $this->classStack ? end($this->classStack) . '::' : '';
                $fullName = $prefix . $node->name->name;
                $this->methodStack[] = $fullName;
                $this->methodReturns[$fullName] = 0;
                $this->methodLines[$fullName] = $node->getStartLine();
            }

            return null;
        }

        public function leaveNode(Node $node): mixed
        {
            if ($node instanceof Node\Stmt\ClassLike) {
                array_pop($this->classStack);

                return null;
            }

            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
                --$this->closureDepth;

                return null;
            }

            if ($node instanceof ClassMethod || $node instanceof Function_) {
                array_pop($this->methodStack);
            }

            return null;
        }
    };

    $traverser->addVisitor(new ParentConnectingVisitor());
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    foreach ($methodReturns as $methodName => $count) {
        if ($count > $maxReturns) {
            $relativePath = str_replace(realpath($srcDir) . '/', 'src/', $filePath);
            $line = $methodLines[$methodName] ?? '?';
            $violations[] = sprintf(
                '%s:%s — %s() has %d returns (max %d)',
                $relativePath,
                $line,
                $methodName,
                $count,
                $maxReturns,
            );
        }
    }

    ++$filesChecked;
}

if ([] === $violations) {
    echo sprintf("OK: All methods in %d files have ≤%d return statements.\n", $filesChecked, $maxReturns);
    exit(0);
}

echo sprintf(
    "\n%d violation(s) found across %d files scanned (max %d returns):\n\n",
    count($violations),
    $filesChecked,
    $maxReturns,
);
foreach ($violations as $v) {
    echo "  - {$v}\n";
}
echo "\n";
exit(1);
