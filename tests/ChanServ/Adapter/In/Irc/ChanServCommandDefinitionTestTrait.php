<?php

declare(strict_types=1);

namespace App\Tests\ChanServ\Adapter\In\Irc;

use App\Shared\Adapter\In\Irc\CommandArgument\Argument;
use App\Shared\Adapter\In\Irc\CommandArgument\CommandArgumentDefinition;

/** Keeps test-only ChanServ command doubles compatible with the expanded command contract. */
trait ChanServCommandDefinitionTestTrait
{
    public function getArgumentDefinition(): CommandArgumentDefinition
    {
        $arguments = [];
        for ($index = 0; $index < $this->getMinArgs(); ++$index) {
            $arguments[] = new Argument('arg' . $index);
        }

        return new CommandArgumentDefinition($this->getSyntaxKey(), $arguments);
    }
}
