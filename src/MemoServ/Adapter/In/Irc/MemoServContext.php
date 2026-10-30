<?php

declare(strict_types=1);

namespace App\MemoServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\MemoServ\Application\Model\MemoAccountView;
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

use function is_scalar;
use function is_string;
use function trim;

final readonly class MemoServContext implements IrcopAuthorizationSubject
{
    /**
     * @param list<string>       $args
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function __construct(
        public ?SenderView $sender,
        public ?MemoAccountView $senderAccount,
        public string $command,
        public array $args,
        private MemoServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private string $language,
        private string $timezone,
        private string $messageType,
        private MemoServCommandRegistry $registry,
        private ServiceNicknameRegistry $serviceNicks,
        private ?IrcRenderer $renderer = null,
    ) {}

    public function getSender(): ?SenderView
    {
        return $this->sender;
    }

    public function getSenderAccount(): ?MemoAccountView
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
        if (null === $role || null === $this->renderer) {
            $this->sendRaw($this->trans($key, $params));

            return;
        }

        $this->sendRaw($this->renderer->reply($role, $this->translateWithCommonFallback($key, []), $this->presentationValues($params)));
    }

    /** @param array<string, mixed> $params */
    public function replyError(string $key, array $params = []): void
    {
        if (null === $this->renderer) {
            $this->reply($key, $params);

            return;
        }
        if ('error.not_identified' === $key) {
            $params['identify'] = new Fragment($this->trans('presentation.identify'), Component::Command);
        }
        if (isset($params['nick']) && !isset($params['nickname'])) {
            $params['nickname'] = $params['nick'];
        }
        foreach (['channel', 'nickname'] as $name) {
            if (isset($params[$name]) && is_string($params[$name])) {
                $params[$name] = new Fragment($params[$name], Component::Emphasis);
            }
        }
        foreach (['syntax', 'operation'] as $name) {
            if (isset($params[$name]) && is_string($params[$name])) {
                $params[$name] = new Fragment($params[$name], Component::Command);
            }
        }
        $this->reply('presentation.' . $key, $params, Role::Error);
    }

    /** @param array<string, mixed> $params */
    public function replyComponent(Component $component, string $key, array $params = []): void
    {
        if (null === $this->renderer) {
            $this->reply($key, $params);

            return;
        }

        $this->sendRaw($this->renderer->component($component, $this->translateWithCommonFallback($key, []), $this->presentationValues($params)));
    }

    /** @param array<string, string|Fragment> $params */
    public function renderComponent(Component $component, string $template, array $params = []): string
    {
        $renderer = $this->renderer ?? new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));

        return $renderer->component($component, $template, $params);
    }

    public function separator(int $width = 29): string
    {
        $renderer = $this->renderer ?? new IrcRenderer(new FixedStyleProvider(PresentationStyle::default()));

        return $renderer->separator($width);
    }

    public function replySeparator(int $width = 29): void
    {
        $this->sendRaw(null === $this->renderer ? $this->trans('reply.separator') : $this->separator($width));
    }

    public function commandFragment(string $text): Fragment|string
    {
        return null === $this->renderer ? $text : new Fragment($text, Component::Command);
    }

    public function emphasize(string $text): Fragment|string
    {
        return null === $this->renderer ? $text : new Fragment($text, Component::Emphasis);
    }

    public function replyRaw(string $message): void
    {
        $this->sendRaw($message);
    }

    public function getNotifier(): MemoServNotifierInterface
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

    public function formatDate(?DateTimeInterface $date): string
    {
        if (null === $date) {
            return '—';
        }

        $dt = $date instanceof DateTimeImmutable ? $date : DateTimeImmutable::createFromInterface($date);

        return $dt->setTimezone(new DateTimeZone($this->timezone))->format('d/m/Y H:i T');
    }

    public function getRegistry(): MemoServCommandRegistry
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
    private function presentationValues(array $params): array
    {
        $values = [];
        foreach ($this->wrapParams($params) as $name => $value) {
            if ($value instanceof Fragment) {
                $values[$name] = $value;
            } elseif (null === $value || is_scalar($value) || $value instanceof Stringable) {
                $values[$name] = (string) $value;
            } else {
                throw new InvalidArgumentException('IRC presentation parameters must be text or fragments.');
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $params */
    private function translateWithCommonFallback(string $key, array $params): string
    {
        if ($this->translator instanceof TranslatorBagInterface
            && $this->translator->getCatalogue($this->language)->has($key, 'common')
        ) {
            return $this->translator->trans($key, $params, 'common', $this->language);
        }

        return $this->translator->trans($key, $params, 'memoserv', $this->language);
    }

    private function sendRaw(string $message): void
    {
        if (null === $this->sender) {
            return;
        }

        $this->notifier->sendMessage($this->sender->uid, $message, $this->messageType);
    }
}
