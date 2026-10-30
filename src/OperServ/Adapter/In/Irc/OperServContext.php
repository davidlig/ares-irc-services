<?php

declare(strict_types=1);

namespace App\OperServ\Adapter\In\Irc;

use App\Irc\Application\Port\In\SenderView;
use App\Irc\Application\Port\In\ServiceNicknameRegistry;
use App\NickServ\Application\Port\In\NickAccountData;
use App\OperServ\Application\Port\In\OperatorActor;
use App\OperServ\Application\Port\In\OperatorAuthorizationAttribute;
use App\OperServ\Application\Port\In\OperatorAuthorizationQuery;
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
use function rtrim;
use function trim;

final readonly class OperServContext
{
    /** @var list<string> */
    public array $args;

    /**
     * @param list<string>       $args
     * @param 'NOTICE'|'PRIVMSG' $messageType
     */
    public function __construct(
        public ?SenderView $sender,
        public ?NickAccountData $senderAccount,
        public string $command,
        array $args,
        private OperServNotifierInterface $notifier,
        private TranslatorInterface $translator,
        private string $language,
        private string $timezone,
        private string $messageType,
        private OperServCommandRegistry $registry,
        private ServiceNicknameRegistry $serviceNicks,
        private OperatorAuthorizationQuery $authorization,
        private ?IrcRenderer $renderer = null,
    ) {
        $this->args = $args;
    }

    public function senderAccountId(): ?int
    {
        return $this->senderAccount?->id;
    }

    /** @param array<string, mixed> $params */
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
        foreach ($params as $name => $value) {
            if (!is_string($value)) {
                continue;
            }
            $parameter = trim($name, '%');
            if ('channel' === $parameter || 'nickname' === $parameter) {
                $params[$name] = new Fragment($value, Component::Emphasis);
            } elseif ('syntax' === $parameter || 'operation' === $parameter) {
                $params[$name] = new Fragment($value, Component::Command);
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

    public function commandFragment(string $text): Fragment|string
    {
        return null === $this->renderer ? $text : new Fragment($text, Component::Command);
    }

    public function emphasize(string $text): Fragment|string
    {
        return null === $this->renderer ? $text : new Fragment($text, Component::Emphasis);
    }

    public function fragment(string $text, Component $component): Fragment|string
    {
        return null === $this->renderer ? $text : new Fragment($text, $component);
    }

    public function selectedStatus(bool $selected): string
    {
        if (null === $this->renderer) {
            return $this->trans($selected ? 'role.perms.list.assigned' : 'role.perms.list.unassigned');
        }

        return rtrim($this->renderer->component($selected ? Component::Selected : Component::Unselected, ' '));
    }

    public function danger(string $text): Fragment|string
    {
        return null === $this->renderer ? $this->renderComponent(Component::Danger, $text) : new Fragment($text, Component::Danger);
    }

    public function replyRaw(string $message): void
    {
        $this->sendRaw($message);
    }

    /** @param array<string, mixed> $params */
    public function trans(string $key, array $params = []): string
    {
        return $this->translateWithCommonFallback($key, $this->wrapParams($params));
    }

    /** @param array<string, mixed> $params */
    public function transInDomain(string $key, string $domain, array $params = []): string
    {
        return $this->translator->trans($key, $this->wrapParams($params), $domain, $this->language);
    }

    public function formatDate(?DateTimeInterface $date): string
    {
        if (null === $date) {
            return '—';
        }

        $immutable = $date instanceof DateTimeImmutable ? $date : DateTimeImmutable::createFromInterface($date);

        return $immutable->setTimezone(new DateTimeZone($this->timezone))->format('d/m/Y H:i T');
    }

    public function commandRegistry(): OperServCommandRegistry
    {
        return $this->registry;
    }

    public function getBotName(): string
    {
        return $this->notifier->getNick();
    }

    public function isAuthorized(?string $permission): bool
    {
        if (null === $this->sender || null === $this->senderAccount) {
            return false;
        }

        $actor = new OperatorActor(
            nickname: $this->sender->nick,
            identifiedAccountId: $this->senderAccount->id,
            identified: $this->sender->isIdentified,
            ircOperator: $this->sender->isOper,
        );

        return match ($permission) {
            null => $this->authorization->ircOperator($actor)->granted,
            OperatorAuthorizationAttribute::IDENTIFIED => $this->authorization->identifiedAccount($actor)->granted,
            OperatorAuthorizationAttribute::ROOT => $this->authorization->root($actor)->granted,
            default => $this->authorization->permission($actor, $permission)->granted,
        };
    }

    /** @param array<string, mixed> $params
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

        return $this->translator->trans($key, $params, 'operserv', $this->language);
    }

    private function sendRaw(string $message): void
    {
        if (null !== $this->sender) {
            $this->notifier->sendMessage($this->sender->uid, $message, $this->messageType);
        }
    }
}
