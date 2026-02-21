<?php

declare(strict_types=1);

namespace App\Domain\NickServ\ValueObject;

enum NickStatus: string
{
    /** Registration started; email verification pending. */
    case Pending = 'pending';

    /** Fully registered and active account. */
    case Registered = 'registered';

    /** Account exists but has been temporarily disabled. */
    case Suspended = 'suspended';

    /** Nick is permanently blocked from being registered by anyone. */
    case Forbidden = 'forbidden';
}
