<?php

declare(strict_types=1);

namespace App\Tests\OperServ\Adapter\Out\Service;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\Irc\Application\Port\In\ChannelServiceActionsPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\NickServ\Application\Port\In\NickAccountQuery;
use App\OperServ\Adapter\In\Irc\OperServNotifierInterface;
use App\OperServ\Adapter\Out\Service\OperServDebugNotifier;
use App\OperServ\Application\Port\In\AuthorizationDecision;
use App\OperServ\Application\Port\In\AuthorizationGrant;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

#[CoversClass(OperServDebugNotifier::class)]
final class OperServDebugNotifierTest extends TestCase
{
    #[Test]
    public function isConfiguredReturnsFalseWhenChannelIsNull(): void
    {
        $debug = $this->createDebugNotifier(debugChannel: null);

        self::assertFalse($debug->isConfigured());
    }

    #[Test]
    public function isConfiguredReturnsFalseWhenChannelIsEmpty(): void
    {
        $debug = $this->createDebugNotifier(debugChannel: '');

        self::assertFalse($debug->isConfigured());
    }

    #[Test]
    public function isConfiguredReturnsTrueWhenChannelIsSet(): void
    {
        $debug = $this->createDebugNotifier(debugChannel: '#ircops');

        self::assertTrue($debug->isConfigured());
    }

    #[Test]
    public function ensureChannelJoinedCallsChannelActionsWhenConfigured(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService')->with('#ircops');

        $debug = $this->createDebugNotifier(
            channelActions: $channelActions,
            debugChannel: '#ircops',
        );

        $debug->ensureChannelJoined();
    }

    #[Test]
    public function ensureChannelJoinedDoesNothingWhenNotConfigured(): void
    {
        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::never())->method('joinChannelAsService');

        $debug = $this->createDebugNotifier(
            channelActions: $channelActions,
            debugChannel: null,
        );

        $debug->ensureChannelJoined();
    }

    #[Test]
    public function notifySendsRawMessageWhenConfigured(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage')
            ->with('#ircops', 'raw debug message', 'NOTICE');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService')->with('#ircops');

        $debug = $this->createDebugNotifier(
            channelActions: $channelActions,
            notifier: $notifier,
            debugChannel: '#ircops',
        );

        $debug->notify('raw debug message');
    }

    #[Test]
    public function notifyDoesNothingWhenNotConfigured(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            debugChannel: null,
        );

        $debug->notify('raw debug message');
    }

    #[Test]
    public function logSendsToChannelWhenConfigured(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            channelActions: $channelActions,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $debug->log('Admin', 'KILL', 'BadUser', 'user@host', '10.0.0.1', 'Flooding');
    }

    #[Test]
    public function logSendsMessageWithoutReasonWhenReasonIsNull(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->willReturnCallback(static fn (string $id, array $params) => $id);

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            channelActions: $channelActions,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $debug->log('Admin', 'KILL', 'BadUser', 'user@host', '10.0.0.1', null);
    }

    #[Test]
    public function logIncludesExtraInContext(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::never())->method('sendMessage');

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            debugChannel: null,
        );

        $debug->log('Admin', 'KILL', 'BadUser', null, null, null, ['key' => 'value']);
    }

    #[Test]
    public function logForGlobalCommandUsesSpecialFormat(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())->method('trans')
            ->with('debug.action_global', self::callback(static fn (array $params) => isset($params['%type%'])
                && 'PRIVMSG' === $params['%type%']
                && '4' === $params['%count%']
                && 'test message' === $params['%message%']));

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            channelActions: $channelActions,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $debug->log('Admin', 'GLOBAL', 'ChanServ', null, null, 'test message', ['type' => 'PRIVMSG', 'count' => '4']);
    }

    #[Test]
    public function isIrcopOrRootReturnsTrueForRoot(): void
    {
        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('RootUser', 42, true, false)))
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::RootIdentity));
        $debug = $this->createDebugNotifier(
            nickAccounts: $nickAccounts,
            authorization: $authorization,
        );

        self::assertTrue($debug->isIrcopOrRoot('RootUser', true, false));
    }

    #[Test]
    public function isIrcopOrRootReturnsTrueForIdentifiedIrcop(): void
    {
        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('OperUser', 42, true, true)))
            ->willReturn(AuthorizationDecision::grantedBy(AuthorizationGrant::IrcOperatorStatus));

        $debug = $this->createDebugNotifier(
            nickAccounts: $nickAccounts,
            authorization: $authorization,
        );

        self::assertTrue($debug->isIrcopOrRoot('OperUser', true, true));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForNonOper(): void
    {
        $debug = $this->createDebugNotifier();

        self::assertFalse($debug->isIrcopOrRoot('NormalUser', false, false));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForOperNotIdentified(): void
    {
        $debug = $this->createDebugNotifier();

        self::assertFalse($debug->isIrcopOrRoot('SomeUser', false, true));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForIdentifiedNonIrcop(): void
    {
        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(42);
        $authorization = $this->createMock(OperatorAuthorizationQuery::class);
        $authorization->expects(self::once())
            ->method('ircOperator')
            ->with(self::equalTo(new OperatorActor('NormalUser', 42, true, true)))
            ->willReturn(AuthorizationDecision::denied());

        $debug = $this->createDebugNotifier(
            nickAccounts: $nickAccounts,
            authorization: $authorization,
        );

        self::assertFalse($debug->isIrcopOrRoot('NormalUser', true, true));
    }

    #[Test]
    public function isIrcopOrRootReturnsFalseForIdentifiedUserWithoutRegisteredNick(): void
    {
        $nickAccounts = $this->createStub(NickAccountQuery::class);
        $nickAccounts->method('findIdByNick')->willReturn(null);

        $debug = $this->createDebugNotifier(
            nickAccounts: $nickAccounts,
        );

        self::assertFalse($debug->isIrcopOrRoot('UnknownUser', true, true));
    }

    #[Test]
    public function getDebugChannelReturnsConfiguredChannel(): void
    {
        $debug = $this->createDebugNotifier(debugChannel: '#ircops');

        self::assertSame('#ircops', $debug->getDebugChannel());
    }

    #[Test]
    public function getDebugChannelReturnsNullWhenNotConfigured(): void
    {
        $debug = $this->createDebugNotifier(debugChannel: null);

        self::assertNull($debug->getDebugChannel());
    }

    #[Test]
    public function logWithDurationInMessage(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')
            ->willReturnCallback(static function (string $key, array $params = []): string {
                if ('debug.action_duration' === $key) {
                    self::assertArrayHasKey('%duration%', $params);
                    self::assertIsString($params['%duration%']);

                    return 'GLINE added with duration ' . $params['%duration%'];
                }

                return $key;
            });

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            channelActions: $channelActions,
            translator: $translator,
            debugChannel: '#ircops',
        );

        $debug->log('Admin', 'GLINE', 'user@host', null, null, 'Spam', ['duration' => '1d']);
    }

    #[Test]
    public function logWithEmptyReasonDoesNotFormat(): void
    {
        $notifier = $this->createMock(OperServNotifierInterface::class);
        $notifier->expects(self::once())->method('sendMessage');

        $channelActions = $this->createMock(ChannelServiceActionsPort::class);
        $channelActions->expects(self::once())->method('joinChannelAsService');

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::atLeastOnce())->method('trans')
            ->willReturnCallback(static function (string $key, array $params = []): string {
                // When reason is null/empty, we don't use prefix translation
                return 'Debug message';
            });

        $debug = $this->createDebugNotifier(
            notifier: $notifier,
            channelActions: $channelActions,
            translator: $translator,
            debugChannel: '#ircops',
        );

        // Just test that it works with empty reason
        $debug->log('Admin', 'KILL', 'BadUser', 'user@host', '127.0.0.1', null, []);
    }

    #[Test]
    public function getServiceNameReturnsOperserv(): void
    {
        $debug = $this->createDebugNotifier();

        self::assertSame('operserv', $debug->getServiceName());
    }

    #[Test]
    public function getUserLookupReturnsConfiguredLookup(): void
    {
        $userLookup = $this->createStub(NetworkUserLookupPort::class);
        $debug = $this->createDebugNotifier(userLookup: $userLookup);

        self::assertSame($userLookup, $debug->getUserLookup());
    }

    private function createDebugNotifier(
        ?ChannelServiceActionsPort $channelActions = null,
        ?NetworkUserLookupPort $userLookup = null,
        ?OperServNotifierInterface $notifier = null,
        ?NickAccountQuery $nickAccounts = null,
        ?OperatorAuthorizationQuery $authorization = null,
        ?TranslatorInterface $translator = null,
        ?string $debugChannel = '#ircops',
        string $language = 'en',
    ): OperServDebugNotifier {
        return new OperServDebugNotifier(
            channelActions: $channelActions ?? $this->createStub(ChannelServiceActionsPort::class),
            userLookup: $userLookup ?? $this->createStub(NetworkUserLookupPort::class),
            notifier: $notifier ?? $this->createStub(OperServNotifierInterface::class),
            nickAccounts: $nickAccounts ?? $this->createStub(NickAccountQuery::class),
            authorization: $authorization ?? $this->deniedAuthorization(),
            translator: $translator ?? $this->createStub(TranslatorInterface::class),
            defaultLanguage: $language,
            debugChannel: $debugChannel,
        );
    }

    private function deniedAuthorization(): OperatorAuthorizationQuery
    {
        $authorization = $this->createStub(OperatorAuthorizationQuery::class);
        $authorization->method('ircOperator')->willReturn(AuthorizationDecision::denied());

        return $authorization;
    }

    #[Test]
    public function realCatalogDebugOutputRemainsIdenticalInEveryLocale(): void
    {
        // Hashes pin the complete nine-message transcript before common extraction.
        $expectedHashes = [
            'ca' => 'dbf4548d8eace03c5d2ad1bd736fb679069ec28f12c9f541adf90cef10f71587',
            'de' => 'f596866c8b52882817fb0dc6094dd60893933f35bb6e4c00f57a4ff09ee0b8a3',
            'el' => '0c25dddaf82a01daef0788ebf3aec3e15a3e2fb85d3fcad456418c47e41e1310',
            'en' => 'bfb08ad9fb1d580e6fc9ec52bab0d16a9d6ab916e52d38857156d30ac986248c',
            'es' => '2e43e37cb7413783aef9c27cf5c1adf8bf1fa18e843aa813a3f3acd414397ff3',
            'eu' => '553a9f23b9066d7d03df3ee3aaf3e30bcf889efea4030ea76e47d1dc65a28288',
            'fr' => '51d97b8e986051c8c8af852ee2cd672cc50d0973a1cd43e3d864b0ba0cace016',
            'gl' => '2a145b00552f82f7c3aefb7f6daf7f89f2526999323c6bf84515867318a5bcf7',
            'it' => '73ecb852d16c73571aa40ec145c7b4d10ae75d3713c760c7d404b8ccc0962892',
            'nl' => '0636752fd65ea15b5a686d4b153f336f0d05d3f76432cd32fa7e2ebe4a4ac5d9',
            'pl' => 'e8db6ef45dd48e10b627393ab7277ca6cb4a3ea4f09f474c892682a9b2046829',
            'pt' => '6a07e3cb7d418238f10fc6e254cb1a727e64e9135edf6799d8afa9f63105e13d',
            'ro' => '654755bf327d6a9d644a71d135e717e6bd4d85698a6924d856c8059618e57c04',
            'tr' => '13849d5eada302d93db37bebea3564e991c03a7de156765f8acf246e14b73923',
        ];
        foreach ($expectedHashes as $locale => $hash) {
            $translator = new Translator($locale);
            $translator->addLoader('yaml', new IrcYamlTranslationLoader());
            foreach (['common', 'operserv'] as $domain) {
                $translator->addResource('yaml', __DIR__ . '/../../../../../translations/' . $domain . '.' . $locale . '.yaml', $locale, $domain);
            }
            $messages = [];
            $transport = $this->createMock(OperServNotifierInterface::class);
            $transport->expects(self::exactly(9))->method('sendMessage')->willReturnCallback(
                static function (string $target, string $message, string $transport) use (&$messages): void {
                    $messages[] = [$target, $message, $transport];
                },
            );
            $debug = $this->createDebugNotifier(notifier: $transport, translator: $translator, debugChannel: '#debug', language: $locale);
            $cases = [
                ['DROP', null, []],
                ['DROP', 'manual', []],
                ['SET', 'manual', ['option' => 'EMAIL', 'value' => 'new@example.test']],
                ['SET', null, ['option' => 'PASSWORD', 'value' => 'secretvalue']],
                ['SET', null, ['option' => 'EMAIL']],
                ['SUSPEND', 'manual', ['duration' => '1d']],
                ['DROP', null, ['founder_action' => true]],
                ['KILL', 'message', ['reasonType' => 'message']],
                ['GLOBAL', 'hello', ['type' => 'NOTICE', 'count' => 2]],
            ];
            foreach ($cases as [$command, $reason, $extra]) {
                $debug->log('Admin', $command, 'Target', null, null, $reason, $extra);
            }
            self::assertSame($hash, hash('sha256', serialize($messages)), $locale);
        }
    }
}
