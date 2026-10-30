<?php

declare(strict_types=1);

namespace App\ChanServ\Adapter\In\Irc\Command;

use App\ChanServ\Adapter\In\Irc\ChanServContext;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentValidator;
use App\Shared\Adapter\In\Irc\Presentation\Component;
use App\Shared\Adapter\In\Irc\Presentation\Fragment;
use App\Shared\Adapter\In\Irc\Presentation\Role;

use function array_values;

/** Shared metadata projection and contextual structure validation for ChanServ adapters. */
trait ChanServArgumentDefinitionSupport
{
    abstract public function getArgumentDefinition(): CommandArgumentDefinition;

    public function getMinArgs(): int
    {
        return $this->getArgumentDefinition()->minimumArgs();
    }

    public function getSyntaxKey(): string
    {
        return $this->getArgumentDefinition()->syntaxKey;
    }

    /**
     * @return bool False when a known variant or unknown-variant fallback has invalid structure.
     *              Unknown variants without fallback syntax remain available to existing replies.
     */
    protected function validateArguments(ChanServContext $context, ?string $directSyntaxKey = null): bool
    {
        $result = CommandArgumentValidator::validate($this->getArgumentDefinition(), array_values($context->args));
        if (null === $result->syntaxKey) {
            return true;
        }

        $syntaxKey = (string) $result->syntaxKey;
        if ($directSyntaxKey === $syntaxKey) {
            $context->reply($syntaxKey);

            return false;
        }

        $context->reply('presentation.error.syntax', ['syntax' => new Fragment($context->trans($syntaxKey), Component::Command)], Role::Error);

        return false;
    }
}
