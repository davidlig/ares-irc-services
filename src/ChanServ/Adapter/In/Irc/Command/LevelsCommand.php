<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServCommandInterface;
use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevels;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsAction;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsHandlerInterface;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsOutcome;
use App\ChanServ\Application\UseCase\ManageLevels\ManageChannelLevelsResult;
use App\ChanServ\Domain\Entity\ChannelLevel;
use App\ChanServ\Domain\Exception\ChannelNotRegisteredException;
use App\ChanServ\Domain\Exception\InsufficientAccessException;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use DateTimeImmutable;

use function in_array;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * LEVELS <#channel> LIST|SET|RESET [level] [value].
 *
 * Founder only. LIST/SET hide keys that depend on unsupported modes
 * (AUTOADMIN, AUTOHALFOP, ADMINDEADMIN, HALFOPDEHALFOP).
 */
final readonly class LevelsCommand implements ChanServCommandInterface
{
    use ChanServArgumentDefinitionSupport;

    /** Level keys that require admin mode (a). Hidden if !hasAdmin(). */
    private const array ADMIN_LEVEL_KEYS = [
        ChannelLevel::KEY_AUTOADMIN,
        ChannelLevel::KEY_ADMINDEADMIN,
    ];

    /** Level keys that require halfop mode (h). Hidden if !hasHalfOp(). */
    private const array HALFOP_LEVEL_KEYS = [
        ChannelLevel::KEY_AUTOHALFOP,
        ChannelLevel::KEY_HALFOPDEHALFOP,
    ];

    /** All level keys in display order (excluding mode-dependent when filtered). */
    private const array ALL_LEVEL_KEYS = [
        ChannelLevel::KEY_AUTOADMIN,
        ChannelLevel::KEY_AUTOOP,
        ChannelLevel::KEY_AUTOHALFOP,
        ChannelLevel::KEY_AUTOVOICE,
        ChannelLevel::KEY_SET,
        ChannelLevel::KEY_ADMINDEADMIN,
        ChannelLevel::KEY_OPDEOP,
        ChannelLevel::KEY_HALFOPDEHALFOP,
        ChannelLevel::KEY_VOICEDEVOICE,
        ChannelLevel::KEY_INVITE,
        ChannelLevel::KEY_UNBAN,
        ChannelLevel::KEY_ACCESSLIST,
        ChannelLevel::KEY_ACCESSCHANGE,
        ChannelLevel::KEY_MEMOREAD,
        ChannelLevel::KEY_MEMOCHANGE,
        ChannelLevel::KEY_AKICK,
        ChannelLevel::KEY_NOJOIN,
    ];

    public function __construct(
        private ManageChannelLevelsHandlerInterface $manageLevels,
    ) {}

    public function getName(): string
    {
        return 'LEVELS';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        return new CommandArgumentDefinition(
            syntaxKey: 'levels.syntax',
            arguments: [new Argument('channel'), new Argument('option')],
            variants: [
                'LIST' => new CommandArgumentDefinition(
                    syntaxKey: 'levels.list.syntax',
                ),
                'SET' => new CommandArgumentDefinition(
                    syntaxKey: 'levels.set.syntax',
                    arguments: [
                        new Argument('level', nonBlank: true),
                        new Argument('value', nonBlank: true),
                    ],
                ),
                'RESET' => new CommandArgumentDefinition(
                    syntaxKey: 'levels.reset.syntax',
                ),
            ],
        );
    }

    public function getHelpKey(): string
    {
        return 'levels.help';
    }

    public function getExampleKey(): string
    {
        return 'levels.example';
    }

    public function getOrder(): int
    {
        return 9;
    }

    public function getShortDescKey(): string
    {
        return 'levels.short';
    }

    public function getSubCommandHelp(): array
    {
        return [
            ['name' => 'LIST', 'desc_key' => 'levels.list.short', 'help_key' => 'levels.list.help', 'example_key' => 'levels.list.example', 'syntax_key' => 'levels.list.syntax'],
            ['name' => 'SET', 'desc_key' => 'levels.set.short', 'help_key' => 'levels.set.help', 'example_key' => 'levels.set.example', 'syntax_key' => 'levels.set.syntax'],
            ['name' => 'RESET', 'desc_key' => 'levels.reset.short', 'help_key' => 'levels.reset.help', 'example_key' => 'levels.reset.example', 'syntax_key' => 'levels.reset.syntax'],
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
        if (null === $context->sender) {
            return;
        }
        $channelName = $context->getChannelNameArg(0);
        if (null === $channelName) {
            $context->reply('error.invalid_channel');

            return;
        }

        if (!$this->validateArguments($context)) {
            return;
        }

        $sub = strtoupper($context->args[1] ?? '');
        $modeSupport = $context->getChannelModeSupport();
        $action = match ($sub) {
            'LIST' => ManageChannelLevelsAction::List,
            'SET' => ManageChannelLevelsAction::Set,
            'RESET' => ManageChannelLevelsAction::Reset,
            default => ManageChannelLevelsAction::Unknown,
        };
        $levelKey = strtoupper(trim($context->args[2] ?? ''));
        $valueString = trim($context->args[3] ?? '');
        $senderAccount = $context->senderAccount;
        $result = $this->manageLevels->handle(new ManageChannelLevels(
            $channelName,
            null === $senderAccount ? null : $senderAccount->id,
            $context->isLevelFounder,
            $action,
            $this->visibleLevelKeys($modeSupport),
            $context->sender->nick,
            new DateTimeImmutable(),
            '' === $levelKey ? null : $levelKey,
            '' === $valueString ? null : (int) $valueString,
        ));
        $this->presentResult($context, $channelName, $sub, $result);
    }

    /** @return list<string> */
    private function visibleLevelKeys(ChannelModeSupportInterface $modeSupport): array
    {
        $keys = [];
        foreach (self::ALL_LEVEL_KEYS as $key) {
            if (in_array($key, self::ADMIN_LEVEL_KEYS, true) && !$modeSupport->hasAdmin()) {
                continue;
            }
            if (in_array($key, self::HALFOP_LEVEL_KEYS, true) && !$modeSupport->hasHalfOp()) {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    private function presentResult(ChanServContext $context, string $channelName, string $subcommand, ManageChannelLevelsResult $result): void
    {
        switch ($result->outcome) {
            case ManageChannelLevelsOutcome::ChannelNotRegistered:
                throw ChannelNotRegisteredException::forChannel($channelName);
            case ManageChannelLevelsOutcome::ActorNotAuthenticated:
                $context->reply('error.not_identified');
                break;
            case ManageChannelLevelsOutcome::AccessDenied:
                throw InsufficientAccessException::forOperation($channelName, 'LEVELS');
            case ManageChannelLevelsOutcome::UnknownAction:
                $context->reply('levels.unknown_sub', ['%sub%' => $subcommand]);
                break;
            case ManageChannelLevelsOutcome::Listed:
                $context->reply('levels.list.header', ['%channel%' => $channelName]);
                foreach ($result->levels as $key => $value) {
                    $context->replyRaw(sprintf('  %s %s', $key, $value));
                }
                break;
            case ManageChannelLevelsOutcome::LevelSet:
                $context->reply('levels.set.done', ['%key%' => $result->levelKey ?? '', '%value%' => (string) $result->value]);
                break;
            case ManageChannelLevelsOutcome::LevelsReset:
                $context->reply('levels.reset.done');
                break;
            case ManageChannelLevelsOutcome::UnknownLevel:
                $context->reply('levels.unknown_key', ['%key%' => $result->levelKey ?? '']);
                break;
            case ManageChannelLevelsOutcome::ValueOutOfRange:
                $context->reply('levels.value_range', [
                    '%min%' => (string) ChannelLevel::LEVEL_MIN,
                    '%max%' => (string) ChannelLevel::LEVEL_MAX,
                ]);
                break;
        }
    }
}
