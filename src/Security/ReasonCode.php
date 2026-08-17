<?php

namespace LiveNetworks\LnStarter\Security;

/**
 * Why an event ended the way it did.
 *
 * Deliberately a closed set. Raw exception messages and validation strings are
 * never used as reason codes: they are unbounded, can carry user input, and
 * would let the log distinguish cases the public response must not.
 *
 * Reason codes are INTERNAL. They may distinguish "no such account" from
 * "ineligible account" because that distinction never reaches the HTTP
 * response — see ADR 0001's enumeration-resistance contract.
 */
enum ReasonCode: string
{
    // Principal resolution
    case UnknownPrincipal = 'unknown_principal';
    case IneligiblePrincipal = 'ineligible_principal';
    case EmailChanged = 'email_changed';

    // Proof verification
    case InvalidCode = 'invalid_code';
    case InvalidLinkToken = 'invalid_link_token';
    case RequesterBindingMismatch = 'requester_binding_mismatch';
    case ProofExpired = 'proof_expired';
    case ProofAlreadyConsumed = 'proof_already_consumed';
    case ProofRevoked = 'proof_revoked';
    case CodeLocked = 'code_locked';
    case AttemptNotFound = 'attempt_not_found';
    case ConfirmationContextMissing = 'confirmation_context_missing';

    // Throttling.
    //
    // The dimension matters: an operator responding to a flood needs to know
    // whether one address is being targeted, one network is noisy, one browser
    // session is looping, or a single proof/confirmation context is being
    // hammered. Collapsing the last two into "session" reported the wrong
    // cause, since neither is keyed on the session.
    case RateLimitedEmail = 'rate_limited_email';
    case RateLimitedIp = 'rate_limited_ip';
    case RateLimitedSession = 'rate_limited_session';
    case RateLimitedProof = 'rate_limited_proof';
    case RateLimitedConfirmationContext = 'rate_limited_confirmation_context';

    // Delivery
    case MailTransportFailure = 'mail_transport_failure';
    case DeliveryWindowExpired = 'delivery_window_expired';

    // Session
    case NoActiveSession = 'no_active_session';
    case SiblingAttemptSuperseded = 'sibling_attempt_superseded';

    // Infrastructure
    case PepperUnavailable = 'pepper_unavailable';
    case ConfigurationInvalid = 'configuration_invalid';
    case SinkFailure = 'sink_failure';
    case Unspecified = 'unspecified';
}
