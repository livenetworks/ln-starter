<?php

namespace LiveNetworks\LnStarter\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface AuthEligibility
{
    public function allows(Authenticatable $user): bool;
}
