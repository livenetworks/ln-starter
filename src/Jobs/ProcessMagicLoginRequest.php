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
use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Security\ReasonCode;
use LiveNetworks\LnStarter\Security\RequestContext;
use LiveNetworks\LnStarter\Security\SecurityEventName;
use LiveNetworks\LnStarter\Security\Stopwatch;
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
        SecurityEventLogger $logger,
        ?RequestContext $context = null,
    ): void {
        app()->setLocale($this->locale);
        URL::defaults(['locale' => $this->locale]);

        // Adopt the correlation identity of the HTTP request that queued this
        // job. Without this the delivery events would be orphaned and could not
        // be joined back to the login attempt that caused them. The job still
        // gets its own request_id so retries stay distinguishable.
        $context ??= app(RequestContext::class);
        $context->startJob($this->requestId);

        $principalKey = $logger->principalKey('email:' . $this->canonicalEmail);

        if (now()->greaterThanOrEqualTo(CarbonImmutable::parse($this->expiresAt))) {
            $logger->event(
                eventName: SecurityEventName::DELIVERY_FAILED,
                outcome: Outcome::Failure,
                reasonCode: ReasonCode::DeliveryWindowExpired,
                attemptId: $this->attemptId,
                principalKey: $principalKey,
                authMethod: 'magic_link',
            );
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
            // Internal reason codes may distinguish these two cases; the public
            // HTTP response deliberately cannot. See ADR 0001.
            $logger->event(
                eventName: SecurityEventName::REQUEST_REJECTED,
                outcome: Outcome::Rejected,
                reasonCode: $user ? ReasonCode::IneligiblePrincipal : ReasonCode::UnknownPrincipal,
                attemptId: $this->attemptId,
                principalKey: $principalKey,
                authMethod: 'magic_link',
            );
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

        $stopwatch = Stopwatch::start();

        try {
            Mail::to($user->email)
                ->send(new MagicLinkMail($user, $attempt, $this->linkToken, $this->code));
        } catch (Throwable $exception) {
            $logger->event(
                eventName: SecurityEventName::DELIVERY_FAILED,
                outcome: Outcome::Failure,
                reasonCode: ReasonCode::MailTransportFailure,
                // Class only: transport messages routinely embed the recipient
                // address and parts of the message body.
                context: ['throwable_class' => $exception::class],
                principalKey: $principalKey,
                attemptId: $attempt->getKey(),
                durationMs: $stopwatch->elapsedMs(),
                authMethod: 'magic_link',
            );

            // A generic exception still triggers the queue's retry/failure
            // behaviour without carrying the original message.
            throw new RuntimeException('Magic login delivery failed.');
        }

        $logger->event(
            eventName: SecurityEventName::DELIVERY_SUCCEEDED,
            outcome: Outcome::Success,
            principalKey: $principalKey,
            attemptId: $attempt->getKey(),
            durationMs: $stopwatch->elapsedMs(),
            authMethod: 'magic_link',
        );
    }
}
