<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc\Command;

use App\Bootstrap\Adapter\Out\Translation\IrcYamlTranslationLoader;
use App\ChanServ\Adapter\In\Irc\ChanServCommandRegistry;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Adapter\In\Irc\ChanServDetailPresenter;
use App\ChanServ\Adapter\In\Irc\ChanServNotifierInterface;
use App\ChanServ\Adapter\In\Irc\Command\StatusCommand;
use App\ChanServ\Application\Model\ChanAccountView;
use App\ChanServ\Application\Port\Out\RegisteredChannelRepositoryInterface;
use App\ChanServ\Application\UseCase\ShowRegistrationStatus\ShowRegistrationStatusHandler;
use App\ChanServ\Domain\Entity\RegisteredChannel;
use App\Irc\Adapter\Protocol\NullChannelModeSupport;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\OperServ\Adapter\Out\Security\ConfiguredRootIdentityRegistry;
use App\OperServ\Application\Port\Out\OperatorRoleAccess;
use App\OperServ\Application\Security\OperatorAuthorizationService;
use App\OperServ\Application\Security\OperatorPermissionPolicy;
use App\OperServ\Application\Security\RootAuthorizationPolicy;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

use function dirname;

#[CoversClass(StatusCommand::class)]
#[CoversClass(ChanServDetailPresenter::class)]
final class StatusCommandTest extends TestCase
{
    #[Test]
    public function statusUsesLocalizedStructuredRows(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('irc', new IrcYamlTranslationLoader());
        $translator->addResource('irc', dirname(__DIR__, 6) . '/translations/chanserv.en.yaml', 'en', 'chanserv');
        $messages = $this->executeStatus(null, 10, translatorOverride: $translator);

        self::assertStringContainsString("\x0314Status", $messages[1]);
        self::assertStringContainsString('Not registered', $messages[1]);
        self::assertStringStartsWith('  ', $messages[1]);
        self::assertStringContainsString("\x0314Status\x03\x0F", $messages[1]);
    }

    #[Test]
    public function requesterSeesAlignedRejectionWithoutOperatorAudit(): void
    {
        $messages = $this->executeStatus($this->rejectedChannel(new DateTimeImmutable('+1 hour')), 10);

        self::assertSame([
            'status.header',
            'status.rejected',
            'status.rejection_reason',
            'status.footer',
        ], $messages);
    }

    #[Test]
    public function indefiniteRestrictionOmitsAvailabilityForRequester(): void
    {
        $messages = $this->executeStatus($this->rejectedChannel(null), 10);

        self::assertSame([
            'status.header',
            'status.rejected',
            'status.rejection_reason',
            'status.footer',
        ], $messages);
    }

    #[Test]
    public function rejectionDetailsRemainPrivateFromOtherAccounts(): void
    {
        $messages = $this->executeStatus($this->rejectedChannel(new DateTimeImmutable('+1 hour')), 11);

        self::assertSame(['status.header', 'status.rejected_private', 'status.footer'], $messages);
    }

    #[Test]
    public function anyCentrallyRecognizedIrcopCanSeeAuditWithoutReviewPermission(): void
    {
        $messages = $this->executeStatus($this->rejectedChannel(new DateTimeImmutable('-1 hour')), 11, '#test', true, actorIsIrcop: true);

        self::assertSame([
            'status.header',
            'status.rejected',
            'status.rejection_reason',
            'status.rejection_audit',
            'status.restriction_until',
            'status.footer',
        ], $messages);
    }

    #[Test]
    public function configuredRootCanSeeRejectionAuditWithoutNetworkOperFlag(): void
    {
        $messages = $this->executeStatus($this->rejectedChannel(new DateTimeImmutable('+1 hour')), 11, actorNickname: 'Root');

        self::assertContains('status.rejection_audit', $messages);
        self::assertContains('status.restriction_until', $messages);
    }

    #[Test]
    public function rawOperFlagWithoutCentralRoleIsNotEnoughToSeeRejectionAudit(): void
    {
        $messages = $this->executeStatus($this->rejectedChannel(new DateTimeImmutable('+1 hour')), 11, actorIsIrcop: true);

        self::assertSame(['status.header', 'status.rejected_private', 'status.footer'], $messages);
    }

    #[Test]
    public function exposesMetadataAndPresentsEveryNonRejectedLifecycleStatus(): void
    {
        $command = new StatusCommand(new ShowRegistrationStatusHandler($this->createStub(RegisteredChannelRepositoryInterface::class)));
        self::assertSame('STATUS', $command->getName());
        self::assertSame([], $command->getAliases());
        self::assertSame('status.syntax', $command->getArgumentDefinition()->syntaxKey);
        self::assertSame('status.help', $command->getHelpKey());
        self::assertSame('status.example', $command->getExampleKey());
        self::assertSame(5, $command->getOrder());
        self::assertSame('status.short', $command->getShortDescKey());
        self::assertSame([], $command->getSubCommandHelp());
        self::assertFalse($command->isOperOnly());
        self::assertNull($command->getRequiredPermission());
        self::assertTrue($command->allowsSuspendedChannel());
        self::assertTrue($command->allowsForbiddenChannel());
        self::assertFalse($command->usesLevelFounder());

        $at = new DateTimeImmutable('2026-10-26T12:00:00+00:00');
        $active = RegisteredChannel::register($at, '#active', 10, 'description');
        $suspended = RegisteredChannel::register($at, '#suspended', 10, 'description');
        $suspended->suspend('policy');
        $forbidden = RegisteredChannel::createForbidden($at, '#forbidden', 'policy');
        $pending = RegisteredChannel::createPendingRegistration($at, '#pending', 10, 'description');
        $deleting = RegisteredChannel::register($at, '#deleting', 10, 'description');
        $deleting->markPendingDeletion($at);

        foreach ([
            [null, '#missing', 'status.unregistered'],
            [$active, '#active', 'status.registered'],
            [$suspended, '#suspended', 'status.suspended'],
            [$forbidden, '#forbidden', 'status.forbidden'],
            [$pending, '#pending', 'status.pending'],
            [$deleting, '#deleting', 'status.pending_deletion'],
        ] as [$channel, $name, $key]) {
            self::assertSame(['status.header', $key, 'status.footer'], $this->executeStatus($channel, 10, $name));
        }
    }

    #[Test]
    public function malformedChannelArgumentsAreRejectedBeforeRepositoryLookup(): void
    {
        self::assertSame(["\x0304✗\x03\x0F presentation.error.syntax"], $this->executeStatus(null, 10, commandArgs: []));
        self::assertSame(["\x0304✗\x03\x0F presentation.error.syntax"], $this->executeStatus(null, 10, commandArgs: ['not-a-channel']));
    }

    /**
     * @param list<string>|null $commandArgs
     *
     * @return list<string>
     */
    private function executeStatus(
        ?RegisteredChannel $channel,
        int $requesterAccountId,
        string $channelName = '#test',
        bool $operatorAuthorized = false,
        ?array $commandArgs = null,
        string $actorNickname = 'Requester',
        bool $actorIsIrcop = false,
        ?TranslatorInterface $translatorOverride = null,
    ): array {
        $repository = $this->createStub(RegisteredChannelRepositoryInterface::class);
        $repository->method('findByChannelName')->willReturn($channel);
        $notifier = $this->createStub(ChanServNotifierInterface::class);
        $messages = [];
        $notifier->method('sendMessage')->willReturnCallback(static function (string $target, string $message) use (&$messages): void {
            $messages[] = $message;
        });
        $translationParameters = [];
        if (null === $translatorOverride) {
            $translator = $this->createStub(TranslatorInterface::class);
            $translator->method('trans')->willReturnCallback(static function (string $key, array $parameters) use (&$translationParameters): string {
                $translationParameters[] = ['key' => $key, 'parameters' => $parameters];

                return $key;
            });
        } else {
            $translator = $translatorOverride;
        }
        $context = new ChanServContext(
            new SenderView('UID1', $actorNickname, 'ident', 'host', 'cloak', '127.0.0.1', true, $actorIsIrcop),
            new ChanAccountView($requesterAccountId, 'Requester', 'en'),
            'STATUS',
            $commandArgs ?? [$channelName],
            $notifier,
            $translator,
            'en',
            'UTC',
            'NOTICE',
            new ChanServCommandRegistry([]),
            $this->createStub(ChannelLookupPort::class),
            new NullChannelModeSupport(),
            $this->createStub(NetworkUserLookupPort::class),
            $this->createStub(ServiceNicknameRegistry::class),
        );

        $roleAccess = $this->createStub(OperatorRoleAccess::class);
        $roleAccess->method('hasAssignedRole')->willReturn($operatorAuthorized);
        $authorization = new OperatorAuthorizationService(
            new RootAuthorizationPolicy(new ConfiguredRootIdentityRegistry('Root')),
            new OperatorPermissionPolicy(new RootAuthorizationPolicy(new ConfiguredRootIdentityRegistry('Root')), $roleAccess),
            $roleAccess,
        );
        new StatusCommand(new ShowRegistrationStatusHandler($repository), $authorization)->execute($context);

        if (null !== $channel && null !== $channel->getRegistrationRestrictedUntil()) {
            foreach ($translationParameters as $translation) {
                if ('status.restriction_until' === $translation['key']) {
                    self::assertSame(
                        $channel->getRegistrationRestrictedUntil()->setTimezone(new DateTimeZone('UTC'))->format('d/m/Y H:i T'),
                        $translation['parameters']['%date%'],
                    );
                }
            }
        }

        return $messages;
    }

    private function rejectedChannel(?DateTimeImmutable $restrictedUntil): RegisteredChannel
    {
        $channel = RegisteredChannel::createPendingRegistration(new DateTimeImmutable('-1 day'), '#test', 10, 'description');
        $channel->rejectRegistration('Insufficient information', 2, 'Reviewer', new DateTimeImmutable('-1 hour'), $restrictedUntil);

        return $channel;
    }
}
