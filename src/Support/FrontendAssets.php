<?php

namespace LiveNetworks\LnStarter\Support;

use Illuminate\Foundation\Vite;
use Illuminate\Foundation\ViteException;
use Illuminate\Support\HtmlString;

/**
 * Emit the package's Vite tags, but never at the cost of the page.
 *
 * The auth pages are the entry point of the login flow. `@vite()` throws when
 * the manifest is missing and again when it exists without an entry, so
 * calling it unconditionally meant a consumer who had not run `npm run build`
 * — or a deploy where the asset build was skipped — got HTTP 500 on /login
 * instead of an unstyled but working page. Authentication down, not merely
 * unstyled.
 *
 * Rendering goes through the application's own configured Vite service rather
 * than an inspection of the default paths. An earlier version looked for
 * `public/hot` and `public/build/manifest.json` by hand, which silently
 * dropped every asset for anyone using `useHotFile()`, `useBuildDirectory()`
 * or `useManifestFilename()` — their assets were built and correct, and the
 * package ignored them.
 */
class FrontendAssets
{
    /**
     * @param  list<string>  $entries
     */
    public static function viteTags(array $entries): HtmlString
    {
        if ($entries === []) {
            return new HtmlString('');
        }

        try {
            return app(Vite::class)($entries);
        } catch (ViteException) {
            // ViteException covers exactly the two "not built" cases: no
            // manifest, and a manifest that does not list the entry. It is
            // caught alone on purpose — any other failure is a real error and
            // must keep propagating rather than silently rendering nothing.
            return new HtmlString('');
        }
    }
}
