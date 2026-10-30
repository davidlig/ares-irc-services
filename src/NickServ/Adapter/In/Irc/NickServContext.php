<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Adapter\Out\InMemory\PendingVerificationRegistry;
use App\NickServ\Adapter\Out\InMemory\RecoveryTokenRegistry;
use App\NickServ\Domain\Entity\RegisteredNick;
use App\OperServ\Application\Port\In\IrcopAuthorizationSubject;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\FixedStyleProvider;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\IrcRenderer;
use App\Shared\Adapter\In\Irc\Presentation\PresentationStyle;
use App\Shared\Adapter\In\Irc\Presentation\Role;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use Stringable;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function in_array;
use function is_scalar;
use function is_string;
use function strtr;
use function trim;

/**
 * Carries all the context a NickServ command needs to execute.
 *
 * Commands send replies via reply() / replyLines() rather than directly
 * touching the connection, which keeps them decoupled from IRC transport.
 *
 * The timezone set here (user preference or default) applies to all date/time
 * display: use formatDate() whenever showing a date or time to the user.
 */
readonly class NickServContext implements IrcopAuthorizationSubject
{
    public function __construct(
        public ?SenderView $sender,
        public ?RegisteredNick $senderAccount,
        public string $command,
        /** @var list<string> */
        public array $args,
        private NickServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private string $language,
        /** PHP timezone identifier (e.g. UTC, Europe/Madrid) used when displaying dates. */
        private string $timezone,
        /** @var 'NOTICE'|'PRIVMSG' */
        private string $messageType,
        private NickServCommandRegistry $registry,
        private PendingVerificationRegistry $pendingVerificationRegistry,
        private RecoveryTokenRegistry $recoveryTokenRegistry,
        private ServiceNicknameRegistry $serviceNicks,
        private ?IrcRenderer $renderer = null,
    ) {}

    public function getSender(): ?SenderView
    {
        return $this->sender;
    }

    public function getSenderAccount(): ?RegisteredNick
    {
        return $this->senderAccount;
    }

    public function getSenderAccountId(): ?int
    {
        return $this->senderAccount?->getId();
    }

    public function getSenderNickname(): ?string
    {
        return $this->sender?->nick;
    }

    public function isSenderIdentified(): bool
    {
        return null !== $this->sender && $this->sender->isIdentified;
    }

    public function isSenderIrcOperator(): bool
    {
        return null !== $this->sender && $this->sender->isOper;
    }

    /**
     * Translate a message key and send it to the command sender (NOTICE or PRIVMSG per user preference).
     *
     * Parameter keys are automatically wrapped with % so callers can write
     * ['nickname' => 'foo'] instead of ['%nickname%' => 'foo'].
     *
     * @param array<string, mixed> $params Named placeholder parameters
     */
    public function reply(string $key, array $params = [], ?Role $role = null): void
    {
        if (null === $role || null === $this->renderer) {
            foreach ($params as $name => $value) {
                if ($value instanceof Fragment) {
                    $params[$name] = $this->plainFragment($value);
                }
            }
            $this->sendRaw($this->trans($key, $params));

            return;
        }

        $template = $this->translateWithCommonFallback($key, [], $this->language);
        $this->sendRaw($this->renderer->reply($role, $template, $this->wrapPresentationParams($params)));
    }

    public function hasHelpPresentation(): bool
    {
        return null !== $this->renderer;
    }

    /** @param array<string, mixed> $params */
    public function formatHelp(Component $component, string $key, array $params = []): string
    {
        if (null === $this->renderer) {
            return $this->trans($key, $params);
        }

        return $this->renderer->component($component, $this->translateWithCommonFallback($key, [], $this->language), $this->wrapPresentationParams($params));
    }

    /** @param array<string, mixed> $params */
    public function formatHelpTemplate(Component $component, string $template, array $params = []): string
    {
        if (null === $this->renderer) {
            return $template;
        }

        return $this->renderer->component($component, $template, $this->wrapPresentationParams($params));
    }

    /** @param array<string, mixed> $params */
    public function formatHelpRole(Role $role, string $key, array $params = []): string
    {
        if (null === $this->renderer) {
            return $this->trans($key, $params);
        }

        return $this->renderer->reply($role, $this->translateWithCommonFallback($key, [], $this->language), $this->wrapPresentationParams($params));
    }

    public function helpSeparator(): string
    {
        return $this->renderer?->separator(29) ?? $this->trans('help.footer');
    }

    /**
     * @param array<string, mixed>                    $params
     * @param array<string, array{string, Component}> $fragments
     */
    public function replyComponentWithLocalizedFragments(Component $component, string $key, array $params, array $fragments): void
    {
        if (null === $this->renderer && !$this->translator instanceof TranslatorBagInterface) {
            $this->reply($key, $params);

            return;
        }
        foreach ($fragments as $name => [$fragmentKey, $fragmentComponent]) {
            $params[$name] = $this->localizedFragment($fragmentKey, $fragmentComponent, $params);
        }
        $this->replyComponent($component, $key, $params);
    }

    /**
     * @param array<string, mixed>                    $params
     * @param array<string, array{string, Component}> $fragments
     */
    public function replyWithLocalizedFragments(string $key, array $params, Role $role, array $fragments): void
    {
        if (null === $this->renderer && !$this->translator instanceof TranslatorBagInterface) {
            $this->reply($key, $params);

            return;
        }
        foreach ($fragments as $name => [$fragmentKey, $component]) {
            $params[$name] = $this->localizedFragment($fragmentKey, $component, $params);
        }
        $this->reply($key, $params, $role);
    }

    /** @param array<string, mixed> $params */
    public function replyWithLocalizedFragment(string $key, array $params, Role $role, string $fragmentKey, Component $component): void
    {
        if (null === $this->renderer && !$this->translator instanceof TranslatorBagInterface) {
            $this->reply($key, $params);

            return;
        }
        $params['value'] = new Fragment($this->trans($fragmentKey), $component);
        $this->reply($key, $params, $role);
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $emphasized
     */
    public function replyEmphasized(string $key, array $params, Role $role, array $emphasized): void
    {
        foreach ($params as $name => $value) {
            if (!in_array(trim($name, '%'), $emphasized, true) || $value instanceof Fragment) {
                continue;
            }
            if (null === $value || is_scalar($value) || $value instanceof Stringable) {
                $params[$name] = new Fragment((string) $value, Component::Emphasis);
            } else {
                throw new InvalidArgumentException('Emphasized parameters must be scalar or Stringable.');
            }
        }
        $this->reply($key, $params, $role);
    }

    /** @param array<string, mixed> $params */
    public function localizedFragment(string $key, Component $component, array $params = []): Fragment
    {
        return new Fragment(
            $this->translateWithCommonFallback($key, [], $this->language),
            $component,
            $this->wrapPresentationParams($params),
        );
    }

    public function replyRescueToken(string $key, ?string $token, ?Role $role): void
    {
        $renderer = $this->renderer;
        if (null === $renderer && $this->translator instanceof TranslatorBagInterface) {
            $renderer = new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
        }
        if (null === $renderer) {
            $this->reply($key, ['token' => $token]);

            return;
        }
        $this->sendRaw(RescueTokenPresentation::render($this->translator, $renderer, $key, $this->language, $token ?? '', $this->notifier->getNick(), $role));
    }

    public function replyRescueUnavailable(): void
    {
        $this->replyWithLocalizedFragments('rescue.unavailable', [], Role::Error, [
            'command_0' => ['rescue.unavailable_command_0', Component::Command],
        ]);
    }

    public function replyIdentifyPending(?string $nickname): void
    {
        if (null === $this->renderer && !$this->translator instanceof TranslatorBagInterface) {
            $this->reply('identify.pending', ['nickname' => $nickname]);

            return;
        }
        $this->reply('identify.pending', [
            'first' => $this->localizedFragment('identify.pending_line_1', Component::Text, [
                'nickname' => new Fragment($nickname ?? '', Component::Emphasis),
            ]),
            'second' => $this->localizedFragment('identify.pending_line_2', Component::IndentedRow, [
                'command_0' => $this->localizedFragment('identify.pending_command_0', Component::Command),
                'command_1' => $this->localizedFragment('identify.pending_command_1', Component::Command),
            ]),
        ], Role::Error);
    }

    public function replyNativeAuthentication(): void
    {
        $this->replyWithLocalizedFragments('identify.native_authentication', [], Role::Information, [
            'command_0' => ['identify.native_authentication_command_0', Component::Command],
            'command_1' => ['identify.native_authentication_command_1', Component::Command],
        ]);
    }

    public function replyNotIdentified(): void
    {
        if (null === $this->renderer) {
            $this->reply('error.not_identified');

            return;
        }
        $this->reply('presentation.error.not_identified', [
            'identify' => new Fragment($this->trans('presentation.identify'), Component::Command),
        ], Role::Error);
    }

    public function replyUnknownHelpCommand(string $command): void
    {
        if (null === $this->renderer) {
            $this->reply('unknown_command', ['command' => $command, 'marker' => $this->trans('help.error_marker')]);

            return;
        }
        $this->reply('presentation.unknown_command', [
            'command' => new Fragment($command, Component::Command),
            'help' => new Fragment('HELP', Component::Command),
        ], Role::Error);
    }

    public function replyUnknownHelpOption(string $command, string $option): void
    {
        if (null === $this->renderer) {
            $this->reply('unknown_help_option', ['command' => $command, 'option' => $option]);

            return;
        }
        $this->reply('presentation.unknown_help_option', [
            'command' => new Fragment($command, Component::Command),
            'option' => new Fragment($option, Component::Command),
            'help' => new Fragment('HELP', Component::Command),
        ], Role::Error);
    }

    public function replyMissingNick(string $key, string $nickname): void
    {
        $this->replyCommonError($key, ['nickname' => new Fragment($nickname, Component::Emphasis)]);
    }

    public function replySyntax(string $syntaxKey): void
    {
        $this->replyCommonError('error.syntax', [
            'syntax' => new Fragment($this->trans($syntaxKey), Component::Command),
        ]);
    }

    /** @param array<string, mixed> $params */
    public function replyCommonError(string $key, array $params = []): void
    {
        if (null === $this->renderer) {
            $this->reply($key, $params);

            return;
        }

        $this->reply('presentation.' . $key, $params, Role::Error);
    }

    /**
     * @param array<string, mixed>                    $params
     * @param array<string, array{string, Component}> $localizedFragments
     */
    public function replyComponent(Component $component, string $key, array $params = [], array $localizedFragments = []): void
    {
        if (null === $this->renderer && !$this->translator instanceof TranslatorBagInterface) {
            $this->reply($key, $params);

            return;
        }

        foreach ($localizedFragments as $name => [$translationKey, $fragmentComponent]) {
            $params[$name] = $this->localizedFragment($translationKey, $fragmentComponent, $params);
        }
        if (null === $this->renderer) {
            $this->reply($key, $params);

            return;
        }
        $template = $this->translateWithCommonFallback($key, [], $this->language);
        $this->sendRaw($this->renderer->component($component, $template, $this->wrapPresentationParams($params)));
    }

    public function replySeparator(int $width = 29): void
    {
        if (null === $this->renderer) {
            $this->reply('reply.separator');

            return;
        }
        $this->sendRaw($this->renderer->separator($width));
    }

    /**
     * Send a pre-translated or literal string directly.
     * Use only when the string has already been localised.
     */
    public function replyRawComponent(Component $component, string $text): void
    {
        $this->sendRaw(null === $this->renderer ? $text : $this->renderer->component($component, '%value%', ['%value%' => $text]));
    }

    public function replyRaw(string $message): void
    {
        $this->sendRaw($message);
    }

    public function getNotifier(): NickServNotifierInterface
    {
        return $this->notifier;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    /**
     * Formats a date/time in the user's timezone for display.
     * Use this for any date or time shown to the user so SET TIMEZONE applies everywhere.
     */
    public function formatDate(?DateTimeInterface $date): string
    {
        if (null === $date) {
            return '—';
        }

        $dt = $date instanceof DateTimeImmutable ? $date : DateTimeImmutable::createFromInterface($date);

        return $dt->setTimezone(new DateTimeZone($this->timezone))->format('d/m/Y H:i T');
    }

    public function getRegistry(): NickServCommandRegistry
    {
        return $this->registry;
    }

    public function getPendingVerificationRegistry(): PendingVerificationRegistry
    {
        return $this->pendingVerificationRegistry;
    }

    public function getRecoveryTokenRegistry(): RecoveryTokenRegistry
    {
        return $this->recoveryTokenRegistry;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->translateWithCommonFallback($key, $this->wrapParams($params), $this->language);
    }

    /**
     * Translate using an explicit language instead of the sender's language.
     * Useful when the message targets another user (e.g. KILL reason for a ghost).
     *
     * @param array<string, mixed> $params
     */
    public function transIn(string $key, array $params = [], string $language = ''): string
    {
        $lang = '' !== $language ? $language : $this->language;

        return $this->translateWithCommonFallback($key, $this->wrapParams($params), $lang);
    }

    public function formatDuration(int $seconds): string
    {
        return DurationFormatter::format(
            $seconds,
            fn (string $key, array $params): string => $this->translateDurationKey($key, $params),
        );
    }

    /**
     * Ensures each parameter key is wrapped with % for Symfony's strtr-based translator.
     * Idempotent: keys already wrapped are left unchanged.
     * Also injects %bot% automatically for all translations.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function wrapParams(array $params): array
    {
        $wrapped = $this->serviceNicks->getAllPlaceholders($this->notifier->getNick());
        foreach ($params as $key => $value) {
            $wrapped['%' . trim((string) $key, '%') . '%'] = $value;
        }

        return $wrapped;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, string|Fragment>
     */
    private function wrapPresentationParams(array $params): array
    {
        $wrapped = $this->serviceNicks->getAllPlaceholders($this->notifier->getNick());
        foreach ($params as $key => $value) {
            if ($value instanceof Fragment || is_string($value)) {
                $wrapped['%' . trim($key, '%') . '%'] = $value;
            } elseif (null === $value || is_scalar($value) || $value instanceof Stringable) {
                $wrapped['%' . trim($key, '%') . '%'] = (string) $value;
            } else {
                throw new InvalidArgumentException('Presentation parameters must be scalar, Stringable or Fragment.');
            }
        }

        return $wrapped;
    }

    /** @param array<string, string> $params */
    private function translateDurationKey(string $key, array $params): string
    {
        $wrappedParams = $this->wrapParams($params);
        $translation = $this->translateWithCommonFallback($key, $wrappedParams, $this->language);

        if ($key !== $translation) {
            return $translation;
        }

        if ($this->translator instanceof TranslatorBagInterface
            && !$this->translator->getCatalogue('es')->has($key, 'common')
        ) {
            return $key;
        }

        return $this->translator->trans($key, $wrappedParams, 'common', 'es');
    }

    /** @param array<string, mixed> $params */
    private function translateWithCommonFallback(string $key, array $params, string $locale): string
    {
        if ($this->translator instanceof TranslatorBagInterface
            && $this->translator->getCatalogue($locale)->has($key, 'common')
        ) {
            return $this->translator->trans($key, $params, 'common', $locale);
        }

        return $this->translator->trans($key, $params, 'nickserv', $locale);
    }

    private function plainFragment(Fragment $fragment): string
    {
        $parameters = [];
        foreach ($fragment->parameters as $key => $value) {
            $parameters[$key] = $value instanceof Fragment ? $this->plainFragment($value) : $value;
        }

        return strtr($fragment->text, $parameters);
    }

    private function sendRaw(string $message): void
    {
        if (null === $this->sender) {
            return;
        }

        $this->notifier->sendMessage($this->sender->uid, $message, $this->messageType);
    }
}
