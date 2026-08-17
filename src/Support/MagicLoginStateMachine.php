<?php

namespace LiveNetworks\LnStarter\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use LiveNetworks\LnStarter\Contracts\AuthEligibility;
use LiveNetworks\LnStarter\Models\MagicLoginAttempt;
use RuntimeException;

class MagicLoginStateMachine
{
    public function __construct(
        private readonly MagicLoginProofs $proofs,
        private readonly AuthEligibility $eligibility,
        private readonly SecurityEventLogger $logger,
    ) {}

    public function consumeLink(string $attemptId): ?Authenticatable
    {
        return DB::transaction(function () use ($attemptId) {
            $attempt = MagicLoginAttempt::query()->lockForUpdate()->find($attemptId);

            return $this->consumeEligibleAttempt($attempt, 'link');
        }, 3);
    }

    public function consumeCode(string $attemptId, string $requesterNonce, string $code): ?Authenticatable
    {
        return DB::transaction(function () use ($attemptId, $requesterNonce, $code) {
            $attempt = MagicLoginAttempt::query()->lockForUpdate()->find($attemptId);

            if (!$this->isConsumable($attempt) || $attempt->code_locked_at) {
                return null;
            }

            if (!hash_equals($attempt->requester_nonce_hash, $this->proofs->hashRequesterNonce($requesterNonce))) {
                $this->reject($attempt, 'requester_binding');
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

            $this->reject($attempt, 'invalid_code');

            if ($attempt->code_locked_at) {
                $this->logger->record('auth.magic.code.locked', [
                    'attempt_id' => $attempt->getKey(),
                    'user_id' => $attempt->user_id,
                    'outcome' => 'locked',
                    'count' => $attempt->code_attempts,
                ]);
            }

            return null;
        }, 3);
    }

    private function consumeEligibleAttempt(?MagicLoginAttempt $attempt, string $via): ?Authenticatable
    {
        if (!$this->isConsumable($attempt)) {
            return null;
        }

        $user = $attempt->user;

        if (!$user || !$this->eligibility->allows($user)) {
            $this->reject($attempt, 'ineligible');
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
            $this->reject($attempt, 'email_changed');
            return null;
        }

        $attempt->forceFill([
            'status' => MagicLoginAttempt::STATUS_CONSUMED,
            'consumed_via' => $via,
            'consumed_at' => now(),
        ])->save();

        $this->logger->record('auth.magic.proof.consumed', [
            'attempt_id' => $attempt->getKey(),
            'user_id' => $attempt->user_id,
            'outcome' => 'consumed',
            'consumed_via' => $via,
        ]);

        MagicLoginAttempt::query()
            ->where('user_id', $attempt->user_id)
            ->where($attempt->getKeyName(), '!=', $attempt->getKey())
            ->where('status', MagicLoginAttempt::STATUS_PENDING)
            ->update([
                'status' => MagicLoginAttempt::STATUS_REVOKED,
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);

        return $user;
    }

    private function isConsumable(?MagicLoginAttempt $attempt): bool
    {
        if (!$attempt || !$attempt->isPending()) {
            return false;
        }

        if ($attempt->isExpired()) {
            $attempt->forceFill(['status' => MagicLoginAttempt::STATUS_EXPIRED])->save();
            $this->logger->record('auth.magic.attempt.expired', [
                'attempt_id' => $attempt->getKey(),
                'user_id' => $attempt->user_id,
                'outcome' => 'expired',
            ]);
            return false;
        }

        return true;
    }

    private function reject(MagicLoginAttempt $attempt, string $reason): void
    {
        $this->logger->record('auth.magic.proof.rejected', [
            'attempt_id' => $attempt->getKey(),
            'user_id' => $attempt->user_id,
            'outcome' => 'rejected',
            'reason' => $reason,
        ]);
    }

    private function pepperUnavailable(MagicLoginAttempt $attempt): void
    {
        $this->logger->record('auth.magic.pepper.unavailable', [
            'attempt_id' => $attempt->getKey(),
            'user_id' => $attempt->user_id,
            'outcome' => 'unavailable',
        ]);
    }
}
