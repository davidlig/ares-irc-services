#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Restores getter methods on Domain entities as simple property delegates.
 * This makes both $entity->property and $entity->getProperty() work.
 */

use PhpParser\Node;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter;

require_once __DIR__ . '/../vendor/autoload.php';

// Map of property name => getter method name + return type
// For each entity, specify what getters should exist
$entityGetters = [
    'src/Domain/ChanServ/Entity/RegisteredChannel.php' => [
        'id' => ['getId', 'int'],
        'name' => ['getName', 'string'],
        'nameLower' => ['getNameLower', 'string'],
        'founderNickId' => ['getFounderNickId', 'int'],
        'successorNickId' => ['getSuccessorNickId', '?int'],
        'description' => ['getDescription', 'string'],
        'url' => ['getUrl', '?string'],
        'email' => ['getEmail', '?string'],
        'entrymsg' => ['getEntrymsg', 'string'],
        'topicLock' => ['isTopicLock', 'bool'],
        'mlockActive' => ['isMlockActive', 'bool'],
        'mlock' => ['getMlock', 'string'],
        'mlockParams' => ['getMlockParams', 'array'],
        'secure' => ['isSecure', 'bool'],
        'topic' => ['getTopic', '?string'],
        'lastTopicSetAt' => ['getLastTopicSetAt', '?DateTimeImmutable'],
        'lastTopicSetByNick' => ['getLastTopicSetByNick', '?string'],
        'lastUsedAt' => ['getLastUsedAt', '?DateTimeImmutable'],
        'createdAt' => ['getCreatedAt', 'DateTimeImmutable'],
        'pendingDeletionAt' => ['getPendingDeletionAt', '?DateTimeImmutable'],
        'status' => ['getStatus', 'ChannelStatus'],
        'suspendedReason' => ['getSuspendedReason', '?string'],
        'suspendedUntil' => ['getSuspendedUntil', '?DateTimeImmutable'],
        'forbiddenReason' => ['getForbiddenReason', '?string'],
        'noExpire' => ['isNoExpire', 'bool'],
    ],
    'src/Domain/NickServ/Entity/RegisteredNick.php' => [
        'id' => ['getId', 'int'],
        'nickname' => ['getNickname', 'string'],
        'nicknameLower' => ['getNicknameLower', 'string'],
        'status' => ['getStatus', 'NickStatus'],
        'passwordHash' => ['getPasswordHash', '?string'],
        'email' => ['getEmail', '?string'],
        'language' => ['getLanguage', 'string'],
        'registeredAt' => ['getRegisteredAt', '?DateTimeImmutable'],
        'expiresAt' => ['getExpiresAt', '?DateTimeImmutable'],
        'reason' => ['getReason', '?string'],
        'suspendedUntil' => ['getSuspendedUntil', '?DateTimeImmutable'],
        'pendingDeletionAt' => ['getPendingDeletionAt', '?DateTimeImmutable'],
        'lastSeenAt' => ['getLastSeenAt', '?DateTimeImmutable'],
        'lastQuitMessage' => ['getLastQuitMessage', '?string'],
        'lastConnectIp' => ['getLastConnectIp', '?string'],
        'lastConnectHost' => ['getLastConnectHost', '?string'],
        'private' => ['isPrivate', 'bool'],
        'vhost' => ['getVhost', '?string'],
        'timezone' => ['getTimezone', '?string'],
        'noExpire' => ['isNoExpire', 'bool'],
    ],
    'src/Domain/OperServ/Entity/Motd.php' => [
        'id' => ['getId', 'int'],
        'text' => ['getText', 'string'],
        'enabled' => ['isEnabled', 'bool'],
        'botNickname' => ['getBotNickname', 'string'],
        'messageType' => ['getMessageType', 'string'],
        'creatorNickId' => ['getCreatorNickId', '?int'],
        'createdAt' => ['getCreatedAt', 'DateTimeImmutable'],
        'expiresAt' => ['getExpiresAt', '?DateTimeImmutable'],
        'shownCount' => ['getShownCount', 'int'],
    ],
    'src/Domain/OperServ/Entity/Gline.php' => [
        'id' => ['getId', 'int'],
        'mask' => ['getMask', 'string'],
        'creatorNickId' => ['getCreatorNickId', '?int'],
        'reason' => ['getReason', '?string'],
        'createdAt' => ['getCreatedAt', 'DateTimeImmutable'],
        'expiresAt' => ['getExpiresAt', '?DateTimeImmutable'],
    ],
    'src/Domain/ChanServ/Entity/ChannelAkick.php' => [
        'id' => ['getId', 'int'],
        'channelId' => ['getChannelId', 'int'],
        'creatorNickId' => ['getCreatorNickId', '?int'],
        'mask' => ['getMask', 'string'],
        'reason' => ['getReason', '?string'],
        'createdAt' => ['getCreatedAt', 'DateTimeImmutable'],
        'expiresAt' => ['getExpiresAt', '?DateTimeImmutable'],
    ],
    'src/Domain/MemoServ/Entity/Memo.php' => [
        'id' => ['getId', 'int'],
        'targetNickId' => ['getTargetNickId', '?int'],
        'targetChannelId' => ['getTargetChannelId', '?int'],
        'senderNickId' => ['getSenderNickId', 'int'],
        'message' => ['getMessage', 'string'],
        'createdAt' => ['getCreatedAt', 'DateTimeImmutable'],
        'readAt' => ['getReadAt', '?DateTimeImmutable'],
    ],
    'src/Domain/ChanServ/Entity/ChannelAccess.php' => [
        'id' => ['getId', 'int'],
        'channelId' => ['getChannelId', 'int'],
        'nickId' => ['getNickId', 'int'],
        'level' => ['getLevel', 'int'],
    ],
    'src/Domain/ChanServ/Entity/ChannelLevel.php' => [
        'id' => ['getId', 'int'],
        'channelId' => ['getChannelId', 'int'],
        'levelKey' => ['getLevelKey', 'string'],
        'value' => ['getValue', 'int'],
    ],
    'src/Domain/MemoServ/Entity/MemoIgnore.php' => [
        'id' => ['getId', 'int'],
        'targetNickId' => ['getTargetNickId', '?int'],
        'targetChannelId' => ['getTargetChannelId', '?int'],
        'ignoredNickId' => ['getIgnoredNickId', 'int'],
    ],
    'src/Domain/MemoServ/Entity/MemoSettings.php' => [
        'id' => ['getId', 'int'],
        'targetNickId' => ['getTargetNickId', '?int'],
        'targetChannelId' => ['getTargetChannelId', '?int'],
        'enabled' => ['isEnabled', 'bool'],
    ],
    'src/Domain/OperServ/Entity/OperIrcop.php' => [
        'id' => ['getId', 'int'],
        'nickId' => ['getNickId', 'int'],
        'role' => ['getRole', 'OperRole'],
        'addedAt' => ['getAddedAt', 'DateTimeImmutable'],
        'addedById' => ['getAddedById', '?int'],
        'reason' => ['getReason', '?string'],
    ],
    'src/Domain/OperServ/Entity/OperRole.php' => [
        'id' => ['getId', 'int'],
        'name' => ['getName', 'string'],
        'description' => ['getDescription', 'string'],
        'protected' => ['isProtected', 'bool'],
        'forcedVhostPattern' => ['getForcedVhostPattern', '?string'],
    ],
    'src/Domain/OperServ/Entity/OperPermission.php' => [
        'id' => ['getId', 'int'],
        'name' => ['getName', 'string'],
        'description' => ['getDescription', 'string'],
    ],
    'src/Domain/NickServ/Entity/ForbiddenVhost.php' => [
        'id' => ['getId', 'int'],
        'pattern' => ['getPattern', 'string'],
        'createdByNickId' => ['getCreatedByNickId', '?int'],
        'createdAt' => ['getCreatedAt', 'DateTimeImmutable'],
    ],
    'src/Domain/NickServ/Entity/NickHistory.php' => [
        'id' => ['getId', 'int'],
        'nickId' => ['getNickId', 'int'],
        'action' => ['getAction', 'string'],
        'performedBy' => ['getPerformedBy', 'string'],
        'performedByNickId' => ['getPerformedByNickId', '?int'],
        'performedAt' => ['getPerformedAt', 'DateTimeImmutable'],
        'message' => ['getMessage', 'string'],
        'extraData' => ['getExtraData', 'array'],
    ],
    'src/Domain/ChanServ/Entity/ChannelHistory.php' => [
        'id' => ['getId', 'int'],
        'channelId' => ['getChannelId', 'int'],
        'action' => ['getAction', 'string'],
        'performedBy' => ['getPerformedBy', 'string'],
        'performedByNickId' => ['getPerformedByNickId', '?int'],
        'performedAt' => ['getPerformedAt', 'DateTimeImmutable'],
        'message' => ['getMessage', 'string'],
        'extraData' => ['getExtraData', 'array'],
    ],
    'src/Domain/IRC/Network/NetworkUser.php' => [
        'nick' => ['getNick', 'Nick'],
        'virtualHost' => ['getVirtualHost', 'string'],
        'modes' => ['getModes', 'string'],
    ],
];

$parser = (new ParserFactory())->createForHostVersion();
$printer = new PrettyPrinter\Standard();

$baseDir = __DIR__ . '/../';
$totalGettersAdded = 0;

foreach ($entityGetters as $relativePath => $getterMap) {
    $filePath = $baseDir . $relativePath;

    if (!file_exists($filePath)) {
        echo "  SKIP: {$filePath} not found\n";
        continue;
    }

    $code = file_get_contents($filePath);
    try {
        $ast = $parser->parse($code);
    } catch (Throwable $e) {
        echo "  ERROR parsing {$filePath}: {$e->getMessage()}\n";
        continue;
    }

    // Find existing getter methods to avoid duplicates
    $existingGetters = [];
    foreach ($ast as $node) {
        if ($node instanceof Node\Stmt\Namespace_) {
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Class_) {
                    foreach ($stmt->stmts as $classStmt) {
                        if ($classStmt instanceof ClassMethod) {
                            $existingGetters[$classStmt->name->name] = true;
                        }
                    }
                }
            }
        } elseif ($node instanceof Class_) {
            foreach ($node->stmts as $classStmt) {
                if ($classStmt instanceof ClassMethod) {
                    $existingGetters[$classStmt->name->name] = true;
                }
            }
        }
    }

    // Find the class node and add new getters
    $classModified = false;
    foreach ($ast as $node) {
        if ($node instanceof Node\Stmt\Namespace_) {
            foreach ($node->stmts as $classNode) {
                if ($classNode instanceof Class_) {
                    $added = 0;
                    foreach ($getterMap as $propName => [$methodName, $returnType]) {
                        if (isset($existingGetters[$methodName])) {
                            continue;
                        }
                        $qualifiedReturnType = $returnType;
                        // Handle namespaced types
                        if (str_contains($returnType, '\\') && !str_starts_with($returnType, '\\')) {
                            $qualifiedReturnType = '\\' . $returnType;
                        }
                        // Build getter method
                        $getter = new ClassMethod(
                            new Identifier($methodName),
                            [
                                'flags' => Class_::MODIFIER_PUBLIC,
                                'returnType' => new Name($qualifiedReturnType),
                                'stmts' => [
                                    new Return_(
                                        new PropertyFetch(
                                            new Variable('this'),
                                            new Identifier($propName),
                                        ),
                                    ),
                                ],
                            ],
                        );
                        array_unshift($classNode->stmts, $getter);
                        ++$added;
                    }
                    $classModified = $added > 0;
                    $totalGettersAdded += $added;
                    echo "  {$relativePath}: +{$added} getters\n";
                }
            }
        } elseif ($node instanceof Class_) {
            $added = 0;
            foreach ($getterMap as $propName => [$methodName, $returnType]) {
                if (isset($existingGetters[$methodName])) {
                    continue;
                }
                $qualifiedReturnType = $returnType;
                if (str_contains($returnType, '\\') && !str_starts_with($returnType, '\\')) {
                    $qualifiedReturnType = '\\' . $returnType;
                }
                $getter = new ClassMethod(
                    name: new Identifier($methodName),
                    flags: Class_::MODIFIER_PUBLIC,
                    returnType: new Name($qualifiedReturnType),
                    stmts: [
                        new Return_(
                            new PropertyFetch(
                                new Variable('this'),
                                new Identifier($propName),
                            ),
                        ),
                    ],
                );
                array_unshift($node->stmts, $getter);
                ++$added;
            }
            $classModified = $added > 0;
            $totalGettersAdded += $added;
            echo "  {$relativePath}: +{$added} getters\n";
        }
    }

    if ($classModified) {
        $newCode = $printer->prettyPrintFile($ast);
        file_put_contents($filePath, $newCode);
        // Verify syntax
        exec("php -l {$filePath} 2>&1", $out, $ret);
        if (0 !== $ret) {
            echo "    WARNING: Syntax error in {$filePath}\n";
        }
    }
}

echo "\nTotal: {$totalGettersAdded} getters restored.\n";
