<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ResolveSetting\ResolveChannelSetting;
use App\ChanServ\Application\UseCase\ResolveSetting\ResolveChannelSettingHandlerInterface;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function array_slice;
use function implode;
use function strtoupper;
use function trim;

/**
 * SET <#channel> <option> [value].
 *
 * Value is optional for some options (e.g. SUCCESSOR with no value clears the successor).
 * Option handlers: FOUNDER, SUCCESSOR, DESC, URL, EMAIL, ENTRYMSG,
 * TOPICLOCK, MLOCK, SECURE, PRIVATE. FOUNDER and SUCCESSOR require founder; others require SET level.
 */
final readonly class SetCommand implements ChanServCommandInterface
{
    use ChanServArgumentDefinitionSupport;

    private const array SUPPORTED_OPTIONS = [
        'FOUNDER', 'SUCCESSOR', 'DESC', 'URL', 'EMAIL', 'ENTRYMSG',
        'TOPICLOCK', 'MLOCK', 'SECURE', 'PRIVATE', 'VERBOSE',
    ];

    /** @var array<string, SetOptionHandlerInterface> */
    private array $handlers;

    public function __construct(
        private ResolveChannelSettingHandlerInterface $settingResolver,
        SetFounderHandler $setFounderHandler,
        SetSuccessorHandler $setSuccessorHandler,
        SetDescHandler $setDescHandler,
        SetUrlHandler $setUrlHandler,
        SetEmailHandler $setEmailHandler,
        SetEntrymsgHandler $setEntrymsgHandler,
        SetTopiclockHandler $setTopiclockHandler,
        SetMlockHandler $setMlockHandler,
        SetSecureHandler $setSecureHandler,
        SetPrivateHandler $setPrivateHandler,
        SetVerboseHandler $setVerboseHandler,
    ) {
        $this->handlers = [
            'FOUNDER' => $setFounderHandler,
            'SUCCESSOR' => $setSuccessorHandler,
            'DESC' => $setDescHandler,
            'URL' => $setUrlHandler,
            'EMAIL' => $setEmailHandler,
            'ENTRYMSG' => $setEntrymsgHandler,
            'TOPICLOCK' => $setTopiclockHandler,
            'MLOCK' => $setMlockHandler,
            'SECURE' => $setSecureHandler,
            'PRIVATE' => $setPrivateHandler,
            'VERBOSE' => $setVerboseHandler,
        ];
    }

    public function getName(): string
    {
        return 'SET';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'set.syntax',
            arguments: [new Argument('channel'), new Argument('option')],
            variants: [
                'FOUNDER' => new CommandArgumentDefinition(
                    syntaxKey: 'set.founder.syntax',
                    arguments: [
                        new Argument('nickname', nonBlank: true),
                        new Argument('token', optional: true),
                    ],
                ),
                'SUCCESSOR' => new CommandArgumentDefinition(
                    syntaxKey: 'set.successor.syntax',
                    arguments: [
                        new Argument('nickname', optional: true, rest: true),
                    ],
                ),
                'DESC' => new CommandArgumentDefinition(
                    syntaxKey: 'set.desc.syntax',
                    arguments: [
                        new Argument('description', rest: true, nonBlank: true),
                    ],
                ),
                'URL' => new CommandArgumentDefinition(
                    syntaxKey: 'set.url.syntax',
                    arguments: [
                        new Argument('url', optional: true, rest: true),
                    ],
                ),
                'EMAIL' => new CommandArgumentDefinition(
                    syntaxKey: 'set.email.syntax',
                    arguments: [
                        new Argument('email', optional: true, rest: true),
                    ],
                ),
                'ENTRYMSG' => new CommandArgumentDefinition(
                    syntaxKey: 'set.entrymsg.syntax',
                    arguments: [
                        new Argument('message', optional: true, rest: true),
                    ],
                ),
                'TOPICLOCK' => new CommandArgumentDefinition(
                    syntaxKey: 'set.topiclock.syntax',
                    arguments: [
                        new Argument('state', alternatives: ['ON', 'OFF']),
                    ],
                    allowExtra: false,
                ),
                'MLOCK' => new CommandArgumentDefinition(
                    syntaxKey: 'set.mlock.syntax',
                    arguments: [
                        new Argument('state', alternatives: ['ON', 'OFF']),
                    ],
                    allowExtra: false,
                ),
                'SECURE' => new CommandArgumentDefinition(
                    syntaxKey: 'set.secure.syntax',
                    arguments: [
                        new Argument('state', alternatives: ['ON', 'OFF']),
                    ],
                    allowExtra: false,
                ),
                'PRIVATE' => new CommandArgumentDefinition(
                    syntaxKey: 'set.private.syntax',
                    arguments: [
                        new Argument('state', alternatives: ['ON', 'OFF']),
                    ],
                    allowExtra: false,
                ),
                'VERBOSE' => new CommandArgumentDefinition(
                    syntaxKey: 'set.verbose.syntax',
                    arguments: [
                        new Argument('state', alternatives: ['ON', 'OFF']),
                    ],
                    allowExtra: false,
                ),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'set.help';
    }

    public function getExampleKey(): string
    {
        return 'set.example';
    }

    public function getOrder(): int
    {
        return 4;
    }

    public function getShortDescKey(): string
    {
        return 'set.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'FOUNDER', 'desc_key' => 'set.founder.short', 'help_key' => 'set.founder.help', 'example_key' => 'set.founder.example', 'syntax_key' => 'set.founder.syntax'],
            ['name' => 'SUCCESSOR', 'desc_key' => 'set.successor.short', 'help_key' => 'set.successor.help', 'example_key' => 'set.successor.example', 'syntax_key' => 'set.successor.syntax'],
            ['name' => 'DESC', 'desc_key' => 'set.desc.short', 'help_key' => 'set.desc.help', 'example_key' => 'set.desc.example', 'syntax_key' => 'set.desc.syntax'],
            ['name' => 'URL', 'desc_key' => 'set.url.short', 'help_key' => 'set.url.help', 'example_key' => 'set.url.example', 'syntax_key' => 'set.url.syntax'],
            ['name' => 'EMAIL', 'desc_key' => 'set.email.short', 'help_key' => 'set.email.help', 'example_key' => 'set.email.example', 'syntax_key' => 'set.email.syntax'],
            ['name' => 'ENTRYMSG', 'desc_key' => 'set.entrymsg.short', 'help_key' => 'set.entrymsg.help', 'example_key' => 'set.entrymsg.example', 'syntax_key' => 'set.entrymsg.syntax'],
            ['name' => 'TOPICLOCK', 'desc_key' => 'set.topiclock.short', 'help_key' => 'set.topiclock.help', 'example_key' => 'set.topiclock.example', 'syntax_key' => 'set.topiclock.syntax'],
            ['name' => 'MLOCK', 'desc_key' => 'set.mlock.short', 'help_key' => 'set.mlock.help', 'example_key' => 'set.mlock.example', 'syntax_key' => 'set.mlock.syntax'],
            ['name' => 'SECURE', 'desc_key' => 'set.secure.short', 'help_key' => 'set.secure.help', 'example_key' => 'set.secure.example', 'syntax_key' => 'set.secure.syntax'],
            ['name' => 'PRIVATE', 'desc_key' => 'set.private.short', 'help_key' => 'set.private.help', 'example_key' => 'set.private.example', 'syntax_key' => 'set.private.syntax'],
            ['name' => 'VERBOSE', 'desc_key' => 'set.verbose.short', 'help_key' => 'set.verbose.help', 'example_key' => 'set.verbose.example', 'syntax_key' => 'set.verbose.syntax'],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): string
    {
        return 'IDENTIFIED';
    }

    public function allowsSuspendedChannel(): bool
    {
        return false;
    }

    /** Whether this command is allowed on forbidden channels. */
    public function allowsForbiddenChannel(): bool
    {
        return false;
    }

    public function usesLevelFounder(): bool
    {
        return true;
    }

    public function execute(ChanServContext $context): void
    {
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel', ['%example%' => new Fragment($context->trans('error.invalid_channel_example'), Component::Emphasis)], Role::Error);

            return;
        }

        $senderAccount = $context->senderAccount;
        if (null === $senderAccount) {
            $context->reply('presentation.error.not_identified', ['%identify%' => new Fragment($context->trans('presentation.identify'), Component::Command)], Role::Error);

            return;
        }

        $option = strtoupper($context->args[1] ?? '');
        $handler = $this->handlers[$option] ?? null;

        if (null === $handler) {
            $context->reply('set.unknown_option', [
                '%option%' => new Fragment($option, Component::Emphasis),
                '%options%' => implode(', ', self::SUPPORTED_OPTIONS),
            ], Role::Error);

            return;
        }

        $channel = $this->settingResolver->handle(new ResolveChannelSetting(
            channelName: $channelName,
            actorAccountId: $senderAccount->id,
            founderEquivalent: $context->isLevelFounder,
            option: $option,
        ));

        if (!$this->validateArguments($context, 'set.founder.syntax')) {
            return;
        }

        $value = 'FOUNDER' === $option
            ? (trim($context->args[2] ?? ''))
            : implode(' ', array_slice($context->args, 2));

        $handler->handle($context, $channel, $value);
    }
}
