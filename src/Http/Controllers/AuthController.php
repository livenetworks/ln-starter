<?php

namespace LiveNetworks\LnStarter\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LiveNetworks\LnStarter\DTOs\Message;
use LiveNetworks\LnStarter\Http\LNController;
use LiveNetworks\LnStarter\Mail\MagicLinkMail;
use LiveNetworks\LnStarter\Models\MagicLinkToken;

class AuthController extends LNController
{
    /**
     * Restore locale URL defaults from session.
     *
     * Auth routes run outside the ln.locale middleware, so URL::defaults
     * is not set. Read the locale persisted by SetLocale and apply it
     * so route() helpers can generate localized URLs (e.g. home).
     */
    protected function restoreLocaleDefaults(): void
    {
        $locale = Session::get('locale', config('app.locale'));
        URL::defaults(['locale' => $locale]);
    }
    /**
     * Send a magic link to the given email address.
     */
    public function magicLink(Request $request)
    {
        $key = 'magic-link:' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            return back()->withErrors([
                'email' => __('Too many requests. Please try again in :seconds seconds.', ['seconds' => $seconds]),
            ]);
        }

        RateLimiter::hit($key, 300); // 5 minute decay

        try {
            $validated = $request->validate([
                'email' => 'required|email',
            ]);

            $userModel = config('ln-starter.auth.user_model', 'App\\Models\\User');
            $user = $userModel::where('email', $validated['email'])->first();

            if (!$user) {
                return back()->withErrors([
                    'email' => __('No account found with this email address.'),
                ])->withInput();
            }

            $expiry = config('ln-starter.auth.token_expiry', 15);

            $token = MagicLinkToken::create([
                'user_id'    => $user->id,
                'token'      => Str::random(64),
                'expires_at' => now()->addMinutes($expiry),
            ]);

            try {
                Mail::to($user->email)->send(new MagicLinkMail($user, $token));
            } catch (\Exception $e) {
                $token->delete();
                $message = new Message('error', __('Email Error'), __('Failed to send magic link. Please try again.'));
                return back()->with('message', $message)->withInput();
            }

            Session::put('magic_link_user_id', $user->id);
            Session::put('magic_link_token_id', $token->id);

            return redirect()->route('magic.wait');

        } catch (ValidationException $e) {
            $message = new Message('error', __('Validation Error'), __('Validation error'), $e->errors());
            return $this->respondWith(null, $message);
        }
    }

    /**
     * Show the "check your email" wait page.
     */
    public function magicWait()
    {
        return view('ln-starter::auth.magic_wait');
    }

    /**
     * Poll endpoint — returns JSON with approval status.
     */
    public function magicStatus(Request $request)
    {
        $this->restoreLocaleDefaults();

        $userId  = Session::get('magic_link_user_id');
        $tokenId = Session::get('magic_link_token_id');

        if (!$userId || !$tokenId) {
            return response()->json(['ok' => false, 'error' => 'No session']);
        }

        $token = MagicLinkToken::find($tokenId);

        if (!$token || $token->isExpired()) {
            Session::forget(['magic_link_user_id', 'magic_link_token_id']);
            return response()->json(['ok' => false, 'error' => 'Token expired']);
        }

        if ($token->approved) {
            $user = $token->user;
            $sanctumToken = $user->createToken('auth_token')->plainTextToken;

            Session::forget(['magic_link_user_id', 'magic_link_token_id']);

            $homeRoute = config('ln-starter.auth.home_route', 'home');

            $response = response()->json([
                'ok'       => true,
                'redirect' => route($homeRoute),
                'token'    => $sanctumToken,
                'user'     => [
                    'id'    => $user->id,
                    'email' => $user->email,
                ],
            ]);

            $response->cookie('auth_token', $sanctumToken, 0, '/', null, false, true);

            return $response;
        }

        return response()->json(['ok' => false, 'error' => 'Token not approved yet']);
    }

    /**
     * Show the magic link confirmation page (GET — never consumes the token).
     */
    public function magicShow(string $token)
    {
        $magicToken = MagicLinkToken::where('token', $token)->first();

        return view('ln-starter::auth.magic', [
            'magicToken' => $magicToken,
            'token'      => $token,
        ]);
    }

    /**
     * Consume the magic link token, authenticate, and redirect (POST).
     */
    public function magicConsume(string $token)
    {
        $magicToken = MagicLinkToken::where('token', $token)->first();

        if (!$magicToken || !$magicToken->isValid()) {
            return redirect()->route('auth.magic.show', ['token' => $token]);
        }

        $this->restoreLocaleDefaults();

        $magicToken->update([
            'approved'    => true,
            'approved_at' => now(),
        ]);

        return view('ln-starter::auth.magic_success');
    }

    /**
     * Revoke the current Sanctum token and redirect to login.
     */
    public function logout(Request $request)
    {
        if (!$request->user()) {
            return redirect()->route('login')
                ->withCookie(cookie()->forget('auth_token'));
        }

        try {
            $request->user()->currentAccessToken()?->delete();

            $message = new Message('success', __('Success'), __('Logout successful'));

            return redirect()->route('login')
                ->withCookie(cookie()->forget('auth_token'))
                ->with('message', $message);

        } catch (\Exception $e) {
            $message = new Message('error', __('Logout failed'), $e->getMessage());
            return redirect()->route('login')
                ->withCookie(cookie()->forget('auth_token'))
                ->with('message', $message);
        }
    }
}
