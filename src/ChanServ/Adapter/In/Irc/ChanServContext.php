<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc;

use App\ChanServ\Application\Model\ChanAccountView;
use App\Irc\Application\Port\In\ChannelLookupPort;
use App\Irc\Application\Port\In\ChannelModeSupportInterface;
use App\Irc\Application\Port\In\ChannelView;
use App\Irc\Application\Port\In\NetworkUserLookupPort;
use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
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
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function str_starts_with;
use function trim;

final readonly class ChanServContext implements IrcopAuthorizationSubject
{
    private IrcRenderer $renderer;

    public function __construct(
        public ?SenderView $sender,
        public ?ChanAccountView $senderAccount,
        public string $command,
        /** @var string[] */
        public array $args,
        private ChanServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private string $language,
        private string $timezone,
        /** @var 'NOTICE'|'PRIVMSG' */
        private string $messageType,
        private ChanServCommandRegistry $registry,
        private ChannelLookupPort $channelLookup,
        private ChannelModeSupportInterface $channelModeSupport,
        private NetworkUserLookupPort $userLookup,
        private ServiceNicknameRegistry $serviceNicks,
        public bool $isLevelFounder = false,
        ?IrcRenderer $renderer = null,
    ) {
        $this->renderer = $renderer ?? new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));
    }

    public function getSender(): ?SenderView
    {
        return $this->sender;
    }

    public function getSenderAccount(): ?ChanAccountView
    {
        return $this->senderAccount;
    }

    public function getSenderAccountId(): ?int
    {
        return $this->senderAccount?->id;
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
     * @param array<string, mixed> $params
     */
    public function reply(string $key, array $params = [], ?Role $role = null): void
    {
        if (null === $role) {
            $this->sendRaw($this->trans($key, $params));

            return;
        }

        $this->sendRaw($this->renderReply($role, $key, $params));
    }

    /** @param array<string, mixed> $params */
    public function renderReply(Role $role, string $key, array $params = []): string
    {
        return $this->renderer->reply($role, $this->translateWithCommonFallback($key, []), $this->presentationParams($params));
    }

    /** @param array<string, mixed> $params */
    public function renderComponent(Component $component, string $key, array $params = []): string
    {
        return $this->renderer->component($component, $this->translateWithCommonFallback($key, []), $this->presentationParams($params));
    }

    /** @param array<string, mixed> $params */
    public function renderTextComponent(Component $component, string $template, array $params = []): string
    {
        return $this->renderer->component($component, $template, $this->presentationParams($params));
    }

    public function renderSeparator(int $width): string
    {
        return $this->renderer->separator($width);
    }

    public function replyRaw(string $message): void
    {
        $this->sendRaw($message);
    }

    public function getNotifier(): ChanServNotifierInterface
    {
        return $this->notifier;
    }

    public function getChannelLookup(): ChannelLookupPort
    {
        return $this->channelLookup;
    }

    public function getChannelModeSupport(): ChannelModeSupportInterface
    {
        return $this->channelModeSupport;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    public function formatDate(?DateTimeInterface $date): string
    {
        if (null === $date) {
            return '—';
        }

        $dt = $date instanceof DateTimeImmutable ? $date : DateTimeImmutable::createFromInterface($date);

        return $dt->setTimezone(new DateTimeZone($this->timezone))->format('d/m/Y H:i T');
    }

    public function getRegistry(): ChanServCommandRegistry
    {
        return $this->registry;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->translateWithCommonFallback($key, $this->wrapParams($params));
    }

    /** First argument as channel name (e.g. #channel). Returns null if missing or not channel-like. */
    public function getChannelNameArg(int $index = 0): ?string
    {
        $name = $this->args[$index] ?? '';

        return str_starts_with($name, '#') ? $name : null;
    }

    /** Get current channel state on the network (modes, topic) or null if not on network. */
    public function getChannelView(string $channelName): ?ChannelView
    {
        return $this->channelLookup->findByChannelName($channelName);
    }

    public function getUserLookup(): NetworkUserLookupPort
    {
        return $this->userLookup;
    }

    /**
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
    private function presentationParams(array $params): array
    {
        $values = $this->wrapParams($params);
        $result = [];
        foreach ($values as $key => $value) {
            if ($value instanceof Fragment || is_string($value)) {
                $result[$key] = $value;
            } elseif (is_int($value) || is_float($value) || is_bool($value) || null === $value) {
                $result[$key] = (string) $value;
            } else {
                throw new InvalidArgumentException('IRC presentation parameters must be scalar or fragments.');
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $params */
    private function translateWithCommonFallback(string $key, array $params): string
    {
        if ($this->translator instanceof TranslatorBagInterface
            && $this->translator->getCatalogue($this->language)->has($key, 'common')
        ) {
            return $this->translator->trans($key, $params, 'common', $this->language);
        }

        return $this->translator->trans($key, $params, 'chanserv', $this->language);
    }

    private function sendRaw(string $message): void
    {
        if (null === $this->sender) {
            return;
        }

        $this->notifier->sendMessage($this->sender->uid, $message, $this->messageType);
    }
}
