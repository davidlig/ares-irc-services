<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\In\Irc\Command;

use App\Irc\Application\Port\In\Command\CommandOutcome;
use App\NickServ\Adapter\In\Irc\NickServCommandInterface;
use App\NickServ\Adapter\In\Irc\NickServContext;
use App\NickServ\Application\Port\In\EmailVerificationPolicyInterface;
use App\NickServ\Application\Security\NickServPermission;

use function strtoupper;

/**
 * VREG {ON|OFF|STATUS}; IRC parsing and presentation only.
 *
 * Sets the network-wide e-mail verification policy for nickname registration.
 */
final readonly class VregCommand implements NickServCommandInterface
{
    private const string ACTION_ON = 'ON';

    private const string ACTION_OFF = 'OFF';

    private const string ACTION_STATUS = 'STATUS';

    public function __construct(private EmailVerificationPolicyInterface $policy) {}

    public function getName(): string
    {
        return 'VREG';
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
        return 'vreg.syntax';
    }

    public function getHelpKey(): string
    {
        return 'vreg.help';
    }

    public function getExampleKey(): string
    {
        return 'vreg.example';
    }

    public function getOrder(): int
    {
        return 74;
    }

    public function getShortDescKey(): string
    {
        return 'vreg.short';
    }

    public function getSubCommandHelp(): array
    {
        return [];
    }

    public function isOperOnly(): bool
    {
        return true;
    }

    public function getRequiredPermission(): string
    {
        return NickServPermission::VREG;
    }

    public function getHelpParams(): array
    {
        return [];
    }

    public function execute(NickServContext $context): CommandOutcome
    {
        if (null === $context->sender) {
            return CommandOutcome::rejected();
        }

        return match (strtoupper($context->args[0] ?? '')) {
            self::ACTION_ON => $this->apply($context, true),
            self::ACTION_OFF => $this->apply($context, false),
            self::ACTION_STATUS => $this->status($context),
            default => $this->usage($context),
        };
    }

    private function apply(NickServContext $context, bool $required): CommandOutcome
    {
        $this->policy->setVerificationRequired($required);
        $context->reply($required ? 'vreg.on' : 'vreg.off');

        return CommandOutcome::success();
    }

    private function status(NickServContext $context): CommandOutcome
    {
        $context->reply(
            $this->policy->isVerificationRequired() ? 'vreg.status_on' : 'vreg.status_off',
        );

        return CommandOutcome::success();
    }

    private function usage(NickServContext $context): CommandOutcome
    {
        $context->reply('vreg.usage', ['syntax' => $context->trans('vreg.syntax')]);

        return CommandOutcome::rejected();
    }
}
