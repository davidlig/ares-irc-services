#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Migrates caller code from Domain entity getter methods to property access.
 * Handles getXyz() -> ->xyz and isXyz() -> ->xyz patterns.
 */

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

require_once __DIR__ . '/../vendor/autoload.php';

// Map of removed getter method names to property names
// Every Domain entity property that was converted to public { get; private set; }
$removedGetters = [
    // Generic (shared across many entities)
    'getId' => 'id',
    'getName' => 'name',
    'getStatus' => 'status',
    'getCreatedAt' => 'createdAt',
    'getExpiresAt' => 'expiresAt',
    'getReason' => 'reason',
    'getDescription' => 'description',

    // RegisteredChannel
    'getNameLower' => 'nameLower',
    'getFounderNickId' => 'founderNickId',
    'getSuccessorNickId' => 'successorNickId',
    'getUrl' => 'url',
    'getEmail' => 'email',
    'getEntrymsg' => 'entrymsg',
    'isTopicLock' => 'topicLock',
    'getMlock' => 'mlock',
    'getMlockParams' => 'mlockParams',
    'getMlockParam' => 'mlockParam', // has arg — keep? Actually was removed, callers use ->mlockParams directly
    'isMlockActive' => 'mlockActive',
    'isSecure' => 'secure',
    'getTopic' => 'topic',
    'getLastTopicSetAt' => 'lastTopicSetAt',
    'getLastTopicSetByNick' => 'lastTopicSetByNick',
    'getLastUsedAt' => 'lastUsedAt',
    'getPendingDeletionAt' => 'pendingDeletionAt',
    'getSuspendedReason' => 'suspendedReason',
    'getSuspendedUntil' => 'suspendedUntil',
    'getForbiddenReason' => 'forbiddenReason',
    'isNoExpire' => 'noExpire',

    // RegisteredNick
    'getNickname' => 'nickname',
    'getNicknameLower' => 'nicknameLower',
    'getPasswordHash' => 'passwordHash',
    'getLanguage' => 'language',
    'getRegisteredAt' => 'registeredAt',
    'getLastSeenAt' => 'lastSeenAt',
    'getLastQuitMessage' => 'lastQuitMessage',
    'getLastConnectIp' => 'lastConnectIp',
    'getLastConnectHost' => 'lastConnectHost',
    'isPrivate' => 'private',
    'getVhost' => 'vhost',
    'getTimezone' => 'timezone',
    'getMessageType' => 'messageType',

    // Motd
    'getText' => 'text',
    'isEnabled' => 'enabled',
    'getBotNickname' => 'botNickname',
    'getCreatorNickId' => 'creatorNickId',
    'getShownCount' => 'shownCount',

    // Gline
    'getMask' => 'mask',

    // ChannelAkick
    'getChannelId' => 'channelId',

    // Memo
    'getTargetNickId' => 'targetNickId',
    'getTargetChannelId' => 'targetChannelId',
    'getSenderNickId' => 'senderNickId',
    'getMessage' => 'message',
    'getReadAt' => 'readAt',

    // MemoSettings
    'getIgnoredNickId' => 'ignoredNickId',

    // ChannelAccess
    'getNickId' => 'nickId',
    'getLevel' => 'level',

    // ChannelLevel
    'getLevelKey' => 'levelKey',
    'getValue' => 'value',

    // OperIrcop
    'getAddedAt' => 'addedAt',
    'getAddedById' => 'addedById',
    'getRole' => 'role',

    // OperRole
    'isProtected' => 'protected',
    'getForcedVhostPattern' => 'forcedVhostPattern',

    // ForbiddenVhost
    'getPattern' => 'pattern',
    'getCreatedByNickId' => 'createdByNickId',

    // NickHistory / ChannelHistory
    'getAction' => 'action',
    'getPerformedBy' => 'performedBy',
    'getPerformedByNickId' => 'performedByNickId',
    'getPerformedAt' => 'performedAt',
    'getExtraData' => 'extraData',

    // NetworkUser
    'getNick' => 'nick',
    'getVirtualHost' => 'virtualHost',
    'getModes' => 'modes',
    'getDisplayHost' => 'displayHost',
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

        $visitor = new class($removedGetters, $replaced) extends NodeVisitorAbstract {
            private array $skipMethods = [];

            public function __construct(
                private array $removedGetters,
                private int &$replaced,
            ) {}

            public function leaveNode(Node $node): mixed
            {
                if ($node instanceof MethodCall) {
                    $methodName = $node->name instanceof Identifier ? $node->name->name : null;
                    if (null === $methodName) {
                        return null;
                    }

                    if (isset($this->removedGetters[$methodName])) {
                        $propName = $this->removedGetters[$methodName];

                        // Check if there are arguments — if so, skip
                        // e.g., getMlockParam($letter) should NOT become $letter->mlockParam
                        if ([] !== $node->args && 'getMlockParam' !== $methodName) {
                            return null;
                        }

                        // Special case: getMlockParam($letter) with arg → keep as-is
                        // The caller should access ->mlockParams[$letter] directly, but this is semantic
                        if ([] !== $node->args) {
                            return null;
                        }

                        ++$this->replaced;

                        return new PropertyFetch($node->var, $propName);
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

echo "\nDone: {$totalReplacements} replacements in {$filesModified} files.\n";
