<?php

namespace LiveNetworks\LnStarter\Security;

/**
 * What happened, as a closed vocabulary.
 *
 * `Rejected` is deliberately distinct from `Failure`: a rejected proof is the
 * system working correctly against a bad credential, while a failure is the
 * operation not completing as intended.
 */
enum Outcome: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Rejected = 'rejected';
    case Error = 'error';
    case Pending = 'pending';
}
