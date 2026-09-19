<?php

declare(strict_types=1);

namespace App\NickServ\Adapter\Out\Protocol;

use App\Irc\Application\Port\In\ActiveProtocolModuleHolderInterface;
use App\Irc\Application\Port\In\NativeNicknameAuthenticationInterface;
use App\NickServ\Application\Model\NicknameAuthenticationMode;
use App\NickServ\Application\Port\Out\NicknameAuthenticationModeQuery;

final readonly class ProtocolNicknameAuthenticationModeQuery implements NicknameAuthenticationModeQuery
{
    public function __construct(private ActiveProtocolModuleHolderInterface $connectionHolder) {}

    public function current(): NicknameAuthenticationMode
    {
        if ($this->connectionHolder->getProtocolModule() instanceof NativeNicknameAuthenticationInterface) {
            return NicknameAuthenticationMode::NativeNick;
        }

        return NicknameAuthenticationMode::ServiceCommand;
    }
}
