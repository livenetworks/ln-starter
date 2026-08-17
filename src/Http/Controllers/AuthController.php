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

        if ($this->creationRateLimited($request, $emailKey)) {
            $this->logger->record('auth.magic.request.rate_limited', [
                'request_id' => $requestId,
                'outcome' => 'rate_limited',
            ]);
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

            $this->logger->record('auth.magic.request.accepted', [
                'attempt_id' => $attemptId,
                'request_id' => $requestId,
                'outcome' => 'accepted',
            ]);
            $this->logger->record('auth.magic.delivery.queued', [
                'attempt_id' => $attemptId,
                'request_id' => $requestId,
                'outcome' => 'queued',
            ]);
        } catch (Throwable) {
            $this->logger->record('auth.magic.delivery.failed', [
                'attempt_id' => $attemptId,
                'request_id' => $requestId,
                'outcome' => 'dispatch_failed',
            ]);
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

        if ($this->codeRateLimited($request, $context) || !preg_match('/^\d{6}$/', $code)) {
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
        $attempt = null;

        if (!$this->linkOpenRateLimited($request, $linkHash)) {
            $attempt = MagicLoginAttempt::query()
                ->where('link_token_hash', $linkHash)
                ->where('status', MagicLoginAttempt::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->first();
        }

        $contextId = $this->proofs->generateContextId();

        if ($attempt) {
            $this->storeConfirmationContext($request, $contextId, $attempt);
            $this->logger->record('auth.magic.link.opened', [
                'attempt_id' => $attempt->getKey(),
                'user_id' => $attempt->user_id,
                'outcome' => 'opened',
            ]);
        } else {
            $this->logger->record('auth.magic.proof.rejected', [
                'outcome' => 'invalid_link',
            ]);
        }

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
        if ($this->linkConfirmationRateLimited($request, $context)) {
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
        $userId = $request->user()?->getAuthIdentifier();

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $this->logger->record('auth.logout.succeeded', [
            'user_id' => $userId,
            'outcome' => 'succeeded',
        ]);

        return redirect()->route('login')
            ->with('message', new Message('success', __('Success'), __('Logout successful')));
    }

    private function authenticate(
        Request $request,
        Authenticatable $user,
        string $attemptId,
        string $via
    ): JsonResponse|RedirectResponse {
        try {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->forget([
                self::CODE_SESSION_KEY,
                self::CONFIRMATION_SESSION_KEY,
            ]);

            $this->restoreLocaleDefaults();
            $redirect = route(config('ln-starter.auth.home_route', 'home'));

            $this->logger->record('auth.magic.login.succeeded', [
                'attempt_id' => $attemptId,
                'user_id' => $user->getAuthIdentifier(),
                'outcome' => 'succeeded',
                'consumed_via' => $via,
            ]);

            if ($request->wantsJson()) {
                return response()->json(['ok' => true, 'redirect' => $redirect]);
            }

            return redirect()->to($redirect);
        } catch (Throwable) {
            $this->logger->record('auth.magic.login.failed', [
                'attempt_id' => $attemptId,
                'user_id' => $user->getAuthIdentifier(),
                'outcome' => 'failed',
            ]);

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

    private function creationRateLimited(Request $request, string $emailKey): bool
    {
        return $this->limited([
            ['auth-create-email:' . $emailKey, 5],
            ['auth-create-ip:' . $this->ipKey($request), 20],
            ['auth-create-session:' . $this->sessionKey($request), 5],
        ], 900);
    }

    private function codeRateLimited(Request $request, array $context): bool
    {
        return $this->limited([
            ['auth-code-email:' . ($context['email_key'] ?? 'missing'), 10],
            ['auth-code-session:' . $this->sessionKey($request), 10],
            ['auth-code-ip:' . $this->ipKey($request), 50],
        ], 900);
    }

    private function linkOpenRateLimited(Request $request, string $linkHash): bool
    {
        return $this->limited([
            ['auth-link-proof:' . $linkHash, 10],
            ['auth-link-ip:' . $this->ipKey($request), 100],
        ], 900);
    }

    private function linkConfirmationRateLimited(Request $request, string $context): bool
    {
        return $this->limited([
            ['auth-confirm-context:' . $this->proofs->rateKey('context', $context), 5],
            ['auth-confirm-session:' . $this->sessionKey($request), 5],
            ['auth-confirm-ip:' . $this->ipKey($request), 50],
        ], 900);
    }

    /** @param array<int, array{string, int}> $limits */
    private function limited(array $limits, int $decaySeconds): bool
    {
        foreach ($limits as [$key, $maxAttempts]) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                return true;
            }
        }

        foreach ($limits as [$key]) {
            RateLimiter::hit($key, $decaySeconds);
        }

        return false;
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
