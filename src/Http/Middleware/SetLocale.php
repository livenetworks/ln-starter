<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Parse locale from the first URL segment and set app locale.
     *
     * Expects routes to be wrapped in Route::prefix('{locale}').
     * Validates against config('app.languages') keys.
     * If locale is missing or invalid, redirects to default locale.
     *
     * Usage in routes:
     *   Route::prefix('{locale}')->middleware('ln.locale')->group(function () { ... });
     *
     * Config (app.php or app.languages):
     *   'locale' => 'mk',
     *   'languages' => ['mk' => 'Македонски', 'en' => 'English', 'sq' => 'Shqip'],
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale');
        $supported = array_keys(config('app.languages', []));

        // Fallback: if no languages configured, use app.locale + app.fallback_locale
        if (empty($supported)) {
            $supported = array_unique(array_filter([
                config('app.locale', 'en'),
                config('app.fallback_locale', 'en'),
            ]));
        }

        // Invalid or missing locale → redirect to default with same path
        if (!$locale || !in_array($locale, $supported)) {
            $default = config('app.locale', $supported[0] ?? 'en');
            $path = $request->path();

            // Strip invalid locale prefix if present
            $segments = explode('/', $path);
            if (!empty($segments[0]) && strlen($segments[0]) === 2) {
                array_shift($segments);
            }

            $cleanPath = implode('/', $segments);
            $query = $request->getQueryString();
            $url = '/' . $default . ($cleanPath ? '/' . $cleanPath : '');
            if ($query) {
                $url .= '?' . $query;
            }

            return redirect($url, 302);
        }

        app()->setLocale($locale);

        // Set default route parameter so route() helpers auto-inject locale
        URL::defaults(['locale' => $locale]);

        // Share locale with all views
        view()->share('currentLocale', $locale);

        return $next($request);
    }
}
