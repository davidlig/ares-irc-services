<?php

declare(strict_types=1);

namespace App\Irc\Adapter\Runtime;

use App\Irc\Adapter\Protocol\RawCommandInterception;
use App\Irc\Adapter\Protocol\RawCommandInterceptorInterface;

final readonly class SelectedRawCommandInterceptor implements RawCommandInterceptorInterface
{
    public function __construct(private ProtocolRuntimeModuleInterface $module) {}

    public function intercept(array $arguments): RawCommandInterception
    {
        if (!$this->module instanceof RawCommandInterceptorInterface) {
            return RawCommandInterception::notHandled();
        }

        return $this->module->intercept($arguments);
    }
}
