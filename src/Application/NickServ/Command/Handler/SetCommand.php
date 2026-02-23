<?php

declare(strict_types=1);

namespace App\Application\NickServ\Command\Handler;

use App\Application\NickServ\Command\NickServCommandInterface;
use App\Application\NickServ\Command\NickServContext;
use App\Application\NickServ\Security\NickServPermission;
use App\Application\NickServ\Set\SetEmailHandler;
use App\Application\NickServ\Set\SetLanguageHandler;
use App\Application\NickServ\Set\SetOptionHandlerInterface;
use App\Application\NickServ\Set\SetPasswordHandler;
use App\Application\NickServ\Set\SetPrivateHandler;
use App\Application\NickServ\Set\SetTimezoneHandler;
use App\Application\NickServ\Set\SetVhostHandler;

use function array_slice;
use function implode;
use function strtoupper;

/**
 * SET <option> <value>.
 *
 * Allows a registered and identified user to change their NickServ settings.
 * Delegates each option to a dedicated Set*Handler.
 *
 * Supported options:
 *   SET PASSWORD <new_password>
 *   SET EMAIL    <new_email>              — request change (token sent to current email)
 *   SET EMAIL    <new_email> <token>      — confirm change with token
 *   SET LANGUAGE <code>       (en | es | …)
 *   SET PRIVATE  ON|OFF
 *   SET VHOST    <vhost>|OFF
 *   SET TIMEZONE <timezone>|OFF
 */
final readonly class SetCommand implements NickServCommandInterface
{
    private const array SUPPORTED_OPTIONS = ['PASSWORD', 'EMAIL', 'LANGUAGE', 'PRIVATE', 'VHOST', 'TIMEZONE'];

    /** @var array<string, SetOptionHandlerInterface> */
    private array $handlers;

    public function __construct(
        SetPasswordHandler $setPasswordHandler,
        SetEmailHandler $setEmailHandler,
        SetLanguageHandler $setLanguageHandler,
        SetPrivateHandler $setPrivateHandler,
        SetTimezoneHandler $setTimezoneHandler,
        SetVhostHandler $setVhostHandler,
    ) {
        $this->handlers = [
            'PASSWORD' => $setPasswordHandler,
            'EMAIL' => $setEmailHandler,
            'LANGUAGE' => $setLanguageHandler,
            'PRIVATE' => $setPrivateHandler,
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
        return 1;
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
                'name' => 'PRIVATE',
                'desc_key' => 'set.private.short',
                'help_key' => 'set.private.help',
                'syntax_key' => 'set.private.syntax',
            ],
            [
                'name' => 'TIMEZONE',
                'desc_key' => 'set.timezone.short',
                'help_key' => 'set.timezone.help',
                'syntax_key' => 'set.timezone.syntax',
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

    public function execute(NickServContext $context): void
    {
        if (null === $context->sender) {
            return;
        }

        $account = $context->senderAccount;
        if (null === $account) {
            $context->reply('error.not_identified');

            return;
        }

        $option = strtoupper($context->args[0]);
        $value = implode(' ', array_slice($context->args, 1));

        $handler = $this->handlers[$option] ?? null;
        if (null !== $handler) {
            $handler->handle($context, $account, $value);

            return;
        }

        $context->reply('set.unknown_option', [
            'option' => $option,
            'options' => implode(', ', self::SUPPORTED_OPTIONS),
        ]);
    }
}
