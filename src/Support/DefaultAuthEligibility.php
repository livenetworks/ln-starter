<?php

namespace LiveNetworks\LnStarter\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use LiveNetworks\LnStarter\Contracts\AuthEligibility;

class DefaultAuthEligibility implements AuthEligibility
{
    public function allows(Authenticatable $user): bool
    {
        return true;
    }
}
