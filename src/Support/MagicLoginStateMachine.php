<?php

namespace LiveNetworks\LnStarter\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use LiveNetworks\LnStarter\Contracts\AuthEligibility;
use LiveNetworks\LnStarter\Models\MagicLoginAttempt;
use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Security\ReasonCode;
use LiveNetworks\LnStarter\Security\SecurityEventName;
use RuntimeException;
use Throwable;

class MagicLoginStateMachine
{
    public function __construct(
        private readonly MagicLoginProofs $proofs,
        private readonly AuthEligibility $eligibility,
        private readonly SecurityEventLogger $logger,
    ) {}

    /**
     * Events staged inside a transaction and emitted only after it commits.
     *
     * A sink call must never happen while a row lock is held, and a rolled-back
     * transaction must never produce a success event. Staging both together
     * also guarantees that during concurrent consumption exactly one caller
     * emits `proof.accepted`.
     *
     * @var list<array{0: string, 1: array<string, mixed>}>
     */
    private array $staged = [];

    public function consumeLink(string $attemptId): ?Authenticatable
    {
        return $this->transactionally(function () use ($attemptId) {
            $attempt = MagicLoginAttempt::query()->lockForUpdate()->find($attemptId);

            return $this->consumeEligibleAttempt($attempt, 'link');
        });
    }

    public function consumeCode(string $attemptId, string $requesterNonce, string $code): ?Authenticatable
    {
        return $this->transactionally(function () use ($attemptId, $requesterNonce, $code) {
            $attempt = MagicLoginAttempt::query()->lockForUpdate()->find($attemptId);

            if (!$this->isConsumable($attempt)) {
                return null;
            }

            if ($attempt->code_locked_at) {
                $this->reject($attempt, ReasonCode::CodeLocked);
                return null;
            }

            if (!hash_equals($attempt->requester_nonce_hash, $this->proofs->hashRequesterNonce($requesterNonce))) {
                $this->reject($attempt, ReasonCode::RequesterBindingMismatch);
                return null;
            }

            try {
                $submittedHash = $this->proofs->codeHash($attempt->getKey(), $code, $attempt->pepper_id);
            } catch (RuntimeException) {
                $this->pepperUnavailable($attempt);
                return null;
            }

            // Verification precedes mutation: a correct fifth submission after
            // four failures must remain valid.
            if (hash_equals($attempt->code_hash, $submittedHash)) {
                return $this->consumeEligibleAttempt($attempt, 'code');
            }

            $attempt->code_attempts++;
            if ($attempt->code_attempts >= (int) config('ln-starter.auth.code_max_failures', 5)) {
                $attempt->code_locked_at = now();
            }
            $attempt->save();

            $this->reject($attempt, ReasonCode::InvalidCode);

            if ($attempt->code_locked_at) {
                $this->stage(SecurityEventName::CODE_LOCKED, [
                    'attempt_id' => $attempt->getKey(),
                    'user_id' => $attempt->user_id,
                    'outcome' => 'locked',
                    'reason' => ReasonCode::CodeLocked->value,
                    'count' => $attempt->code_attempts,
                ]);
            }

            return null;
        });
    }

    /**
     * Run the locked transition, then flush staged events.
     *
     * Events are emitted outside the transaction so that no sink call happens
     * while a row lock is held, and are discarded entirely if the transaction
     * throws — a rollback must never leave a success event behind.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    private function transactionally(callable $work): mixed
    {
        $this->staged = [];

        try {
            $result = DB::transaction($work, 3);
        } catch (Throwable $exception) {
            $this->staged = [];
            throw $exception;
        }

        $staged = $this->staged;
        $this->staged = [];

        foreach ($staged as [$eventName, $context]) {
            $this->logger->record($eventName, $context);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function stage(string $eventName, array $context): void
    {
        $this->staged[] = [$eventName, $context];
    }

    private function consumeEligibleAttempt(?MagicLoginAttempt $attempt, string $via): ?Authenticatable
    {
        if (!$this->isConsumable($attempt)) {
            return null;
        }

        $user = $attempt->user;

        if (!$user || !$this->eligibility->allows($user)) {
            $this->reject($attempt, ReasonCode::IneligiblePrincipal);
            return null;
        }

        try {
            $currentEmailKey = $this->proofs->emailKey($user->email, $attempt->pepper_id);
        } catch (RuntimeException) {
            $this->pepperUnavailable($attempt);
            return null;
        }

        if (!hash_equals($attempt->email_key, $currentEmailKey)) {
            $attempt->forceFill([
                'status' => MagicLoginAttempt::STATUS_REVOKED,
                'revoked_at' => now(),
            ])->save();
            $this->reject($attempt, ReasonCode::EmailChanged);
            return null;
        }

        $attempt->forceFill([
            'status' => MagicLoginAttempt::STATUS_CONSUMED,
            'consumed_via' => $via,
            'consumed_at' => now(),
        ])->save();

        // Staged, not emitted: only the transaction that actually commits may
        // claim the proof was accepted.
        $this->stage(SecurityEventName::PROOF_ACCEPTED, [
            'attempt_id' => $attempt->getKey(),
            'user_id' => $attempt->user_id,
            'outcome' => 'consumed',
            'consumed_via' => $via,
        ]);

        $revoked = MagicLoginAttempt::query()
            ->where('user_id', $attempt->user_id)
            ->where($attempt->getKeyName(), '!=', $attempt->getKey())
            ->where('status', MagicLoginAttempt::STATUS_PENDING)
            ->update([
                'status' => MagicLoginAttempt::STATUS_REVOKED,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        if ($revoked > 0) {
            $this->stage(SecurityEventName::SIBLINGS_REVOKED, [
                'attempt_id' => $attempt->getKey(),
                'user_id' => $attempt->user_id,
                'outcome' => 'success',
                'reason' => ReasonCode::SiblingAttemptSuperseded->value,
                'count' => $revoked,
            ]);
        }

        return $user;
    }

    private function isConsumable(?MagicLoginAttempt $attempt): bool
    {
        if (!$attempt) {
            $this->stage(SecurityEventName::PROOF_REJECTED, [
                'outcome' => 'rejected',
                'reason' => ReasonCode::AttemptNotFound->value,
            ]);

            return false;
        }

        // The loser of a concurrent race lands here: it took the row lock
        // second and found a terminal attempt. Returning silently dropped it
        // from the audit trail entirely, so a race looked like a single
        // uncontested login.
        if (!$attempt->isPending()) {
            $this->stage(SecurityEventName::PROOF_REPLAYED, [
                'attempt_id' => $attempt->getKey(),
                'user_id' => $attempt->user_id,
                'outcome' => 'rejected',
                'reason' => match ($attempt->status) {
                    MagicLoginAttempt::STATUS_CONSUMED => ReasonCode::ProofAlreadyConsumed->value,
                    MagicLoginAttempt::STATUS_REVOKED => ReasonCode::ProofRevoked->value,
                    default => ReasonCode::ProofExpired->value,
                },
            ]);

            return false;
        }

        if ($attempt->isExpired()) {
            $attempt->forceFill(['status' => MagicLoginAttempt::STATUS_EXPIRED])->save();
            $this->stage(SecurityEventName::PROOF_EXPIRED, [
                'attempt_id' => $attempt->getKey(),
                'user_id' => $attempt->user_id,
                'outcome' => 'expired',
                'reason' => ReasonCode::ProofExpired->value,
            ]);
            return false;
        }

        return true;
    }

    /**
     * A terminal attempt presented again is a replay, which is a different
     * signal from a bad credential and deserves its own event.
     */
    public function reportReplay(?MagicLoginAttempt $attempt, string $via): void
    {
        if ($attempt === null) {
            $this->logger->event(
                eventName: SecurityEventName::PROOF_REJECTED,
                outcome: Outcome::Rejected,
                reasonCode: ReasonCode::AttemptNotFound,
                authMethod: $via,
            );

            return;
        }

        $this->logger->event(
            eventName: SecurityEventName::PROOF_REPLAYED,
            outcome: Outcome::Rejected,
            reasonCode: match ($attempt->status) {
                MagicLoginAttempt::STATUS_CONSUMED => ReasonCode::ProofAlreadyConsumed,
                MagicLoginAttempt::STATUS_REVOKED => ReasonCode::ProofRevoked,
                default => ReasonCode::ProofExpired,
            },
            attemptId: $attempt->getKey(),
            authMethod: $via,
        );
    }

    private function reject(MagicLoginAttempt $attempt, ReasonCode $reason): void
    {
        $this->stage(SecurityEventName::PROOF_REJECTED, [
            'attempt_id' => $attempt->getKey(),
            'user_id' => $attempt->user_id,
            'outcome' => 'rejected',
            'reason' => $reason->value,
        ]);
    }

    private function pepperUnavailable(MagicLoginAttempt $attempt): void
    {
        $this->stage(SecurityEventName::READINESS_FAILED, [
            'attempt_id' => $attempt->getKey(),
            'user_id' => $attempt->user_id,
            'outcome' => 'error',
            'reason' => ReasonCode::PepperUnavailable->value,
            'pepper_id' => $attempt->pepper_id,
        ]);
    }
}
