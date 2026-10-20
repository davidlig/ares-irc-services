<?php

declare(strict_types=1);

namespace App\OperServ\Application\UseCase\ManageGeoip;

enum ManageGeoipOutcome
{
    case UnknownAction;
    case InvalidRequest;
    case UnknownCountryCode;
    case AlreadyExists;
    case NotFound;
    case ListEmpty;
    case Added;
    case Deleted;
    case Listed;
}
