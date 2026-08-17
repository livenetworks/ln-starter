<?php

namespace LiveNetworks\LnStarter\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\View\View;
use LiveNetworks\LnStarter\DTOs\Message;
use LiveNetworks\LnStarter\Http\LNController;
use LiveNetworks\LnStarter\Jobs\ProcessMagicLoginRequest;
use LiveNetworks\LnStarter\Models\MagicLoginAttempt;
use LiveNetworks\LnStarter\Security\Outcome;
use LiveNetworks\LnStarter\Security\ReasonCode;
use LiveNetworks\LnStarter\Security\SecurityEventName;
use LiveNetworks\LnStarter\Security\Stopwatch;
use LiveNetworks\LnStarter\Support\MagicLoginProofs;
use LiveNetworks\LnStarter\Support\MagicLoginStateMachine;
use LiveNetworks\LnStarter\Support\SecurityEventLogger;
use Throwable;

class AuthController extends LNController
{
    private const CODE_SESSION_KEY = 'ln_starter.auth.code';
    private const CONFIRMATION_SESSION_KEY = 'ln_starter.auth.confirmations';
    private const RATE_SESSION_KEY = 'ln_starter.auth.rate_nonce';

    public function __construct(
        private readonly MagicLoginProofs $proofs,
        private readonly MagicLoginStateMachine $stateMachine,
        private readonly SecurityEventLogger $logger,
    ) {}

    public function login(Request $request): View
    {
        $this->forgetLegacySessionState($request);

        return view('ln-starter::auth.login');
    }

    public function magicLink(Request $request): JsonResponse|RedirectResponse
    {
        $this->forgetLegacySessionState($request);
        $startedAt = hrtime(true);
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
        ]);

        $email = $this->proofs->canonicalEmail($validated['email']);
        $emailKey = $this->proofs->emailKey($email);
        $requestId = $this->logger->requestId();
        $stopwatch = Stopwatch::start();

        // Pseudonymous and versioned: correlates repeated attempts by the same
        // address without ever writing the address itself.
        $principalKey = $this->logger->principalKey('email:' . $email);

        $this->logger->event(
            eventName: SecurityEventName::REQUEST_RECEIVED,
            outcome: Outcome::Pending,
            principalKey: $principalKey,
            authMethod: 'magic_link',
        );

        if ($limitReason = $this->creationRateLimitReason($request, $emailKey)) {
            $this->logger->event(
                eventName: SecurityEventName::RATE_LIMITED,
                outcome: Outcome::Rejected,
                reasonCode: $limitReason,
                principalKey: $principalKey,
                durationMs: $stopwatch->elapsedMs(),
                authMethod: 'magic_link',
            );
            $this->normalizeRequestTiming($startedAt);

            return $this->genericRequestResponse($request);
        }

        $attemptId = (string) Str::ulid();
        $linkToken = $this->proofs->generateLinkToken();
        $code = $this->proofs->generateCode();
        $requesterNonce = $this->proofs->generateRequesterNonce();
        $pepperId = $this->proofs->currentPepperId();
        $expiresAt = now()->addMinutes((int) config('ln-starter.auth.token_expiry', 15));

        $request->session()->put(self::CODE_SESSION_KEY, [
            'attempt_id' => $attemptId,
            'requester_nonce' => $requesterNonce,
            'email_key' => $emailKey,
            'expires_at' => $expiresAt->getTimestamp(),
        ]);

        try {
            ProcessMagicLoginRequest::dispatch(
                $attemptId,
                $email,
                $linkToken,
                $code,
                $this->proofs->hashRequesterNonce($requesterNonce),
                $pepperId,
                $emailKey,
                $expiresAt->toIso8601String(),
                $requestId,
                app()->getLocale(),
            );

            $this->logger->event(
                eventName: SecurityEventName::REQUEST_ACCEPTED,
                outcome: Outcome::Success,
                principalKey: $principalKey,
                attemptId: $attemptId,
                durationMs: $stopwatch->elapsedMs(),
                authMethod: 'magic_link',
            );
            $this->logger->event(
                eventName: SecurityEventName::DELIVERY_QUEUED,
                outcome: Outcome::Pending,
                context: ['queue' => (string) config('queue.default')],
                principalKey: $principalKey,
                attemptId: $attemptId,
                authMethod: 'magic_link',
            );
        } catch (Throwable $exception) {
            $this->logger->event(
                eventName: SecurityEventName::DELIVERY_FAILED,
                outcome: Outcome::Failure,
                reasonCode: ReasonCode::MailTransportFailure,
                context: ['throwable_class' => $exception::class],
                principalKey: $principalKey,
                attemptId: $attemptId,
                durationMs: $stopwatch->elapsedMs(),
                authMethod: 'magic_link',
            );
        }

        $this->normalizeRequestTiming($startedAt);

        return $this->genericRequestResponse($request);
    }

    public function codeForm(): View
    {
        return view('ln-starter::auth.magic_code');
    }

    public function consumeCode(Request $request): JsonResponse|RedirectResponse
    {
        $context = $request->session()->get(self::CODE_SESSION_KEY);
        $code = (string) $request->input('code', '');

        if (
            !is_array($context)
            || ($context['expires_at'] ?? 0) <= now()->getTimestamp()
        ) {
            return $this->proofFailureResponse($request);
        }

        if ($throttled = $this->codeRateLimitReason($request, $context)) {
            $this->logger->event(
                eventName: SecurityEventName::RATE_LIMITED,
                outcome: Outcome::Rejected,
                reasonCode: $throttled,
                attemptId: is_string($context['attempt_id'] ?? null) ? $context['attempt_id'] : null,
                authMethod: 'magic_code',
            );

            return $this->proofFailureResponse($request);
        }

        if (!preg_match('/^\d{6}$/', $code)) {
            return $this->proofFailureResponse($request);
        }

        $user = $this->stateMachine->consumeCode(
            $context['attempt_id'],
            $context['requester_nonce'],
            $code,
        );

        if (!$user) {
            return $this->proofFailureResponse($request);
        }

        return $this->authenticate($request, $user, $context['attempt_id'], 'code');
    }

    public function openLink(Request $request, string $token): RedirectResponse
    {
        $linkHash = $this->proofs->hashLinkToken($token);
        $contextId = $this->proofs->generateContextId();
        $throttled = $this->linkOpenRateLimitReason($request, $linkHash);

        if ($throttled !== null) {
            // Throttling says nothing about the proof itself. Reporting it as a
            // replay would put a false "this token was reused" claim in the
            // audit trail for a perfectly valid pending link.
            $this->logger->event(
                eventName: SecurityEventName::RATE_LIMITED,
                outcome: Outcome::Rejected,
                reasonCode: $throttled,
                authMethod: 'magic_link',
            );

            return $this->linkConfirmationRedirect($contextId);
        }

        $attempt = MagicLoginAttempt::query()
            ->where('link_token_hash', $linkHash)
            ->where('status', MagicLoginAttempt::STATUS_PENDING)
            ->where('expires_at', '>', now())
            ->first();

        if ($attempt) {
            $this->storeConfirmationContext($request, $contextId, $attempt);
            // GET only establishes context — it never consumes or authenticates.
            $this->logger->event(
                eventName: SecurityEventName::LINK_OPENED,
                outcome: Outcome::Pending,
                principalKey: $this->logger->principalKey('user:' . $attempt->user_id),
                attemptId: $attempt->getKey(),
                authMethod: 'magic_link',
            );
        } else {
            // A terminal (already used / revoked) attempt is a replay; a
            // completely unknown hash is just an invalid token.
            $known = MagicLoginAttempt::query()->where('link_token_hash', $linkHash)->first();

            $this->stateMachine->reportReplay($known, 'magic_link');
        }

        return $this->linkConfirmationRedirect($contextId);
    }

    private function linkConfirmationRedirect(string $contextId): RedirectResponse
    {

        return redirect()
            ->route('auth.magic.link.confirm', ['context' => $contextId], 303)
            ->withHeaders([
                'Referrer-Policy' => 'no-referrer',
                'Cache-Control' => 'no-store, private',
            ]);
    }

    public function confirmLink(Request $request, string $context): View
    {
        $entry = $request->session()->get(self::CONFIRMATION_SESSION_KEY . '.' . $context);
        $valid = is_array($entry)
            && ($entry['expires_at'] ?? 0) > now()->getTimestamp()
            && MagicLoginAttempt::query()
                ->whereKey($entry['attempt_id'] ?? '')
                ->where('status', MagicLoginAttempt::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->exists();

        return view('ln-starter::auth.magic', [
            'context' => $context,
            'valid' => $valid,
        ]);
    }

    public function consumeLink(Request $request, string $context): JsonResponse|RedirectResponse
    {
        if ($throttled = $this->linkConfirmationRateLimitReason($request, $context)) {
            $this->logger->event(
                eventName: SecurityEventName::RATE_LIMITED,
                outcome: Outcome::Rejected,
                reasonCode: $throttled,
                authMethod: 'magic_link',
            );

            return $this->proofFailureResponse($request, 'login');
        }

        $entry = $request->session()->pull(self::CONFIRMATION_SESSION_KEY . '.' . $context);

        if (!is_array($entry) || ($entry['expires_at'] ?? 0) <= now()->getTimestamp()) {
            return $this->proofFailureResponse($request, 'login');
        }

        $user = $this->stateMachine->consumeLink($entry['attempt_id']);

        if (!$user) {
            return $this->proofFailureResponse($request, 'login');
        }

        return $this->authenticate($request, $user, $entry['attempt_id'], 'link');
    }

    public function legacyWait(Request $request): RedirectResponse
    {
        $this->forgetLegacySessionState($request);

        return redirect()->route('login')->with(
            'message',
            new Message('info', __('Check your email'), $this->genericRequestText())
        );
    }

    public function legacyStatus(Request $request): JsonResponse
    {
        $this->forgetLegacySessionState($request);

        return response()->json([
            'ok' => false,
            'error' => 'No session',
            'upgrade_required' => true,
        ], 410);
    }

    public function logout(Request $request): RedirectResponse
    {
        $stopwatch = Stopwatch::start();
        $userId = $request->user()?->getAuthIdentifier();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // A logout POST with no active session is a distinct signal: it is what
        // a stale tab or a replayed form looks like, and it should not be
        // reported as a successful session termination.
        $this->logger->event(
            eventName: $userId === null
                ? SecurityEventName::SESSION_TERMINATE_NOOP
                : SecurityEventName::SESSION_TERMINATED,
            outcome: Outcome::Success,
            reasonCode: $userId === null ? ReasonCode::NoActiveSession : null,
            principalKey: $userId === null ? null : $this->logger->principalKey('user:' . $userId),
            durationMs: $stopwatch->elapsedMs(),
            authMethod: 'session',
            guard: 'web',
        );

        return redirect()->route('login')
            ->with('message', new Message('success', __('Success'), __('Logout successful')));
    }

    private function authenticate(
        Request $request,
        Authenticatable $user,
        string $attemptId,
        string $via
    ): JsonResponse|RedirectResponse {
        $stopwatch = Stopwatch::start();
        $principalKey = $this->logger->principalKey('user:' . $user->getAuthIdentifier());
        $authMethod = $via === 'code' ? 'magic_code' : 'magic_link';

        try {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->forget([
                self::CODE_SESSION_KEY,
                self::CONFIRMATION_SESSION_KEY,
            ]);

            $this->restoreLocaleDefaults();
            $redirect = route(config('ln-starter.auth.home_route', 'home'));

            $this->logger->event(
                eventName: SecurityEventName::SESSION_CREATED,
                outcome: Outcome::Success,
                context: ['consumed_via' => $via],
                principalKey: $principalKey,
                attemptId: $attemptId,
                durationMs: $stopwatch->elapsedMs(),
                authMethod: $authMethod,
                guard: 'web',
            );

            if ($request->wantsJson()) {
                return response()->json(['ok' => true, 'redirect' => $redirect]);
            }

            return redirect()->to($redirect);
        } catch (Throwable $exception) {
            $this->logger->event(
                eventName: SecurityEventName::SESSION_CREATED,
                outcome: Outcome::Error,
                context: ['throwable_class' => $exception::class, 'consumed_via' => $via],
                principalKey: $principalKey,
                attemptId: $attemptId,
                durationMs: $stopwatch->elapsedMs(),
                authMethod: $authMethod,
                guard: 'web',
            );

            return $this->proofFailureResponse($request, 'login');
        }
    }

    private function genericRequestResponse(Request $request): JsonResponse|RedirectResponse
    {
        $message = new Message('info', __('Check your email'), $this->genericRequestText());

        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'content' => null], 202);
        }

        return redirect()->route('auth.magic.code.form')->with('message', $message);
    }

    private function proofFailureResponse(Request $request, string $route = 'auth.magic.code.form'): JsonResponse|RedirectResponse
    {
        $message = new Message(
            'error',
            __('Sign in failed'),
            __('The sign-in proof is invalid or expired. Request a new email and try again.')
        );

        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'content' => null], 422);
        }

        return redirect()->route($route)->with('message', $message);
    }

    private function genericRequestText(): string
    {
        return __('If an eligible account exists, a sign-in link and code have been sent.');
    }

    private function storeConfirmationContext(
        Request $request,
        string $contextId,
        MagicLoginAttempt $attempt
    ): void {
        $contexts = $request->session()->get(self::CONFIRMATION_SESSION_KEY, []);
        $now = now()->getTimestamp();

        $contexts = array_filter(
            is_array($contexts) ? $contexts : [],
            static fn ($entry) => is_array($entry) && ($entry['expires_at'] ?? 0) > $now
        );

        $contexts[$contextId] = [
            'attempt_id' => $attempt->getKey(),
            'expires_at' => min($attempt->expires_at->getTimestamp(), now()->addMinutes(5)->getTimestamp()),
            'created_at' => $now,
        ];

        uasort($contexts, static fn ($a, $b) => ($a['created_at'] ?? 0) <=> ($b['created_at'] ?? 0));
        while (count($contexts) > 3) {
            array_shift($contexts);
        }

        $request->session()->put(self::CONFIRMATION_SESSION_KEY, $contexts);
    }

    /**
     * Which throttle tripped, so the audit trail can distinguish a targeted
     * email flood from a noisy shared IP. The public response is identical
     * either way.
     */
    private function creationRateLimitReason(Request $request, string $emailKey): ?ReasonCode
    {
        return $this->limitedBy([
            ['auth-create-email:' . $emailKey, 5, ReasonCode::RateLimitedEmail],
            ['auth-create-ip:' . $this->ipKey($request), 20, ReasonCode::RateLimitedIp],
            ['auth-create-session:' . $this->sessionKey($request), 5, ReasonCode::RateLimitedSession],
        ], 900);
    }

    /**
     * @param array<int, array{string, int, ReasonCode}> $limits
     */
    private function limitedBy(array $limits, int $decaySeconds): ?ReasonCode
    {
        foreach ($limits as [$key, $maxAttempts, $reason]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                return $reason;
            }
        }

        foreach ($limits as [$key]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        return null;
    }

    /**
     * All throttle keys are HMACs or hashes, so the reason code identifies the
     * dimension that tripped without the raw identifier ever being logged.
     */
    private function codeRateLimitReason(Request $request, array $context): ?ReasonCode
    {
        return $this->limitedBy([
            ['auth-code-email:' . ($context['email_key'] ?? 'missing'), 10, ReasonCode::RateLimitedEmail],
            ['auth-code-session:' . $this->sessionKey($request), 10, ReasonCode::RateLimitedSession],
            ['auth-code-ip:' . $this->ipKey($request), 50, ReasonCode::RateLimitedIp],
        ], 900);
    }

    private function linkOpenRateLimitReason(Request $request, string $linkHash): ?ReasonCode
    {
        return $this->limitedBy([
            // Keyed on the proof itself, not on a session.
            ['auth-link-proof:' . $linkHash, 10, ReasonCode::RateLimitedProof],
            ['auth-link-ip:' . $this->ipKey($request), 100, ReasonCode::RateLimitedIp],
        ], 900);
    }

    private function linkConfirmationRateLimitReason(Request $request, string $context): ?ReasonCode
    {
        return $this->limitedBy([
            // Keyed on the confirmation context, not on a session.
            ['auth-confirm-context:' . $this->proofs->rateKey('context', $context), 5, ReasonCode::RateLimitedConfirmationContext],
            ['auth-confirm-session:' . $this->sessionKey($request), 5, ReasonCode::RateLimitedSession],
            ['auth-confirm-ip:' . $this->ipKey($request), 50, ReasonCode::RateLimitedIp],
        ], 900);
    }

    private function sessionKey(Request $request): string
    {
        $nonce = $request->session()->get(self::RATE_SESSION_KEY);
        if (!is_string($nonce) || $nonce === '') {
            $nonce = $this->proofs->generateRequesterNonce();
            $request->session()->put(self::RATE_SESSION_KEY, $nonce);
        }

        return $this->proofs->rateKey('session', $nonce);
    }

    private function ipKey(Request $request): string
    {
        return $this->proofs->rateKey('ip', $request->ip() ?: 'unknown');
    }

    private function normalizeRequestTiming(int $startedAt): void
    {
        $minimum = max(0, (int) config('ln-starter.auth.response_floor_ms', 250));
        $jitter = max(0, (int) config('ln-starter.auth.response_jitter_ms', 50));
        $target = $minimum + ($jitter > 0 ? random_int(0, $jitter) : 0);
        $elapsed = (hrtime(true) - $startedAt) / 1_000_000;

        if ($elapsed < $target) {
            usleep((int) (($target - $elapsed) * 1000));
        }
    }

    private function restoreLocaleDefaults(): void
    {
        $locale = session('locale', config('app.locale'));
        URL::defaults(['locale' => $locale]);
    }

    private function forgetLegacySessionState(Request $request): void
    {
        $request->session()->forget(['magic_link_user_id', 'magic_link_token_id']);
    }
}
