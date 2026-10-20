<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGeoip;

enum GeoipAction
{
    case Add;
    case Delete;
    case List;
    case Unknown;
}
