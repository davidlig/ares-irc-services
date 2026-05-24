<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\SessionLanguageRegistry;
use App\Domain\NickServ\Entity\RegisteredNick;

use function array_slice;
use function count;
use function implode;
use function in_array;
use function strtolower;
use function strtoupper;

/**
 * SET <option> <value>.
 *
 * Allows a registered and identified user to change their own NickServ settings.
 * Delegates each option to a dedicated Set*Handler.
 *
 * The LANGUAGE option is also available to unregistered users: it sets a
 * temporary session language that lasts until they disconnect.
 *
 * For modifying other users' settings, use SASET.
 */
final class SetCommand implements NickServCommandInterface
{
    private const array SUPPORTED_OPTIONS = ['PASSWORD', 'EMAIL', 'LANGUAGE', 'TIMEZONE', 'PRIVATE', 'MSG', 'VHOST'];

    /** @var array<string, SetOptionHandlerInterface> */
    private array $handlers;

    public function __construct(
        SetPasswordHandler $setPasswordHandler,
        SetEmailHandler $setEmailHandler,
        SetLanguageHandler $setLanguageHandler,
        SetPrivateHandler $setPrivateHandler,
        SetMsgHandler $setMsgHandler,
        SetTimezoneHandler $setTimezoneHandler,
        SetVhostHandler $setVhostHandler,
        private readonly SessionLanguageRegistry $sessionLanguageRegistry,
    ) {
        $this->handlers = [
            'PASSWORD' => $setPasswordHandler,
            'EMAIL' => $setEmailHandler,
            'LANGUAGE' => $setLanguageHandler,
            'PRIVATE' => $setPrivateHandler,
            'MSG' => $setMsgHandler,
            'TIMEZONE' => $setTimezoneHandler,
            'VHOST' => $setVhostHandler,
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

    public function getMinArgs(): int
    {
        return 2;
    }

    public function getSyntaxKey(): string
    {
        return 'set.syntax';
    }

    public function getHelpKey(): string
    {
        return 'set.help';
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
            [
                'name' => 'PASSWORD',
                'desc_key' => 'set.password.short',
                'help_key' => 'set.password.help',
                'syntax_key' => 'set.password.syntax',
            ],
            [
                'name' => 'EMAIL',
                'desc_key' => 'set.email.short',
                'help_key' => 'set.email.help',
                'syntax_key' => 'set.email.syntax',
            ],
            [
                'name' => 'LANGUAGE',
                'desc_key' => 'set.language.short',
                'help_key' => 'set.language.help',
                'syntax_key' => 'set.language.syntax',
            ],
            [
                'name' => 'TIMEZONE',
                'desc_key' => 'set.timezone.short',
                'help_key' => 'set.timezone.help',
                'syntax_key' => 'set.timezone.syntax',
            ],
            [
                'name' => 'PRIVATE',
                'desc_key' => 'set.private.short',
                'help_key' => 'set.private.help',
                'syntax_key' => 'set.private.syntax',
            ],
            [
                'name' => 'MSG',
                'desc_key' => 'set.msg.short',
                'help_key' => 'set.msg.help',
                'syntax_key' => 'set.msg.syntax',
            ],
            [
                'name' => 'VHOST',
                'desc_key' => 'set.vhost.short',
                'help_key' => 'set.vhost.help',
                'syntax_key' => 'set.vhost.syntax',
            ],
        ];
    }

    public function isOperOnly(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return NickServPermission::IDENTIFIED_OWNER;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        if (count($context->args) < 2) {
            $context->reply('error.syntax', [
                'syntax' => $context->trans($this->getSyntaxKey()),
            ]);

            return;
        }

        $this->routeSet($context);
    }

    private function routeSet(NickServContext $context): void
    {
        $option = strtoupper($context->args[0]);
        $value = implode(' ', array_slice($context->args, 1));

        if (!in_array($option, self::SUPPORTED_OPTIONS, true)) {
            $context->reply('set.unknown_option', [
                'option' => $option,
                'options' => implode(', ', self::SUPPORTED_OPTIONS),
            ]);

            return;
        }

        $account = $context->senderAccount;

        if ('LANGUAGE' === $option && null === $account) {
            $this->handleLanguageForUnregistered($context, $value);

            return;
        }

        $this->dispatchHandler($context, $account, $option, $value);
    }

    private function dispatchHandler(
        NickServContext $context,
        ?RegisteredNick $account,
        string $option,
        string $value,
    ): void {
        if (null === $account) {
            $context->reply('error.not_identified');

            return;
        }

        $handler = $this->handlers[$option] ?? null;
        // @codeCoverageIgnoreStart
        if (null === $handler) {
            $context->reply('set.unknown_option', [
                'option' => $option,
                'options' => implode(', ', self::SUPPORTED_OPTIONS),
            ]);

            return;
        }
        // @codeCoverageIgnoreEnd

        $handler->handle($context, $account, $value, false);
    }

    private function handleLanguageForUnregistered(NickServContext $context, string $value): void
    {
        $lang = strtolower($value);

        if ('' === $lang) {
            $context->reply('error.syntax', [
                'syntax' => $context->trans('set.language.syntax'),
            ]);

            return;
        }

        if (!in_array($lang, RegisteredNick::SUPPORTED_LANGUAGES, true)) {
            $context->reply('set.language.invalid', [
                'languages' => implode(', ', RegisteredNick::SUPPORTED_LANGUAGES),
            ]);

            return;
        }

        $this->sessionLanguageRegistry->register($context->sender->uid, $lang);
        $context->reply('set.language.success', ['language' => $lang]);
    }
}
