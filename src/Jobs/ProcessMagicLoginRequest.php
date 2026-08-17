<?php

namespace LiveNetworks\LnStarter\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use LiveNetworks\LnStarter\Contracts\AuthEligibility;
use LiveNetworks\LnStarter\Mail\MagicLinkMail;
use LiveNetworks\LnStarter\Models\MagicLoginAttempt;
use LiveNetworks\LnStarter\Support\MagicLoginProofs;
use LiveNetworks\LnStarter\Support\SecurityEventLogger;
use RuntimeException;
use Throwable;

class ProcessMagicLoginRequest implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $attemptId,
        public readonly string $canonicalEmail,
        public readonly string $linkToken,
        public readonly string $code,
        public readonly string $requesterNonceHash,
        public readonly string $pepperId,
        public readonly string $emailKey,
        public readonly string $expiresAt,
        public readonly string $requestId,
        public readonly string $locale,
    ) {}

    public function handle(
        AuthEligibility $eligibility,
        MagicLoginProofs $proofs,
        SecurityEventLogger $logger
    ): void {
        app()->setLocale($this->locale);
        URL::defaults(['locale' => $this->locale]);

        if (now()->greaterThanOrEqualTo(CarbonImmutable::parse($this->expiresAt))) {
            $logger->record('auth.magic.delivery.failed', [
                'attempt_id' => $this->attemptId,
                'request_id' => $this->requestId,
                'outcome' => 'expired_before_delivery',
                'reason' => 'queue_delay',
            ]);
            return;
        }

        $userModel = config('ln-starter.auth.user_model', 'App\\Models\\User');
        $user = $userModel::query()
            ->whereRaw('LOWER(email) = ?', [$this->canonicalEmail])
            ->first();

        // Perform the same purpose-separated proof work before eligibility is
        // known. Unknown/ineligible addresses never produce a usable attempt.
        $linkHash = $proofs->hashLinkToken($this->linkToken);
        $codeHash = $proofs->codeHash($this->attemptId, $this->code, $this->pepperId);

        if (!$user || !$eligibility->allows($user)) {
            $logger->record('auth.magic.proof.rejected', [
                'attempt_id' => $this->attemptId,
                'request_id' => $this->requestId,
                'outcome' => 'ineligible',
            ]);
            return;
        }

        $attempt = MagicLoginAttempt::query()->firstOrCreate(
            ['id' => $this->attemptId],
            [
                'user_id' => $user->getAuthIdentifier(),
                'email_key' => $this->emailKey,
                'pepper_id' => $this->pepperId,
                'link_token_hash' => $linkHash,
                'code_hash' => $codeHash,
                'requester_nonce_hash' => $this->requesterNonceHash,
                'status' => MagicLoginAttempt::STATUS_PENDING,
                'code_attempts' => 0,
                'expires_at' => $this->expiresAt,
            ]
        );

        try {
            Mail::to($user->email)
                ->send(new MagicLinkMail($user, $attempt, $this->linkToken, $this->code));
        } catch (Throwable) {
            $logger->record('auth.magic.delivery.failed', [
                'attempt_id' => $attempt->getKey(),
                'user_id' => $user->getAuthIdentifier(),
                'request_id' => $this->requestId,
                'outcome' => 'failed',
            ]);
            // Keep transport exceptions out of logs because their messages may
            // contain recipient or message data. A generic exception still
            // triggers the queue's normal retry/failure behavior.
            throw new RuntimeException('Magic login delivery failed.');
        }

        $logger->record('auth.magic.delivery.sent', [
            'attempt_id' => $attempt->getKey(),
            'user_id' => $user->getAuthIdentifier(),
            'request_id' => $this->requestId,
            'outcome' => 'sent',
        ]);
    }
}
