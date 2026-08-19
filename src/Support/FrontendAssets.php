<?php

namespace LiveNetworks\LnStarter\Support;

/**
 * Whether the host application has actually built the package's frontend
 * entries.
 *
 * The auth pages are the entry point of the login flow, and @vite() throws
 * when the manifest is missing or does not list an entry. Calling it
 * unconditionally means a consumer who has installed the package but not yet
 * run `npm run build` gets HTTP 500 on /login instead of an unstyled but
 * working page — and any deployment that skips the asset build takes
 * authentication down entirely.
 *
 * The documented flow (installer injects the Vite entry, consumer builds) is
 * unchanged; this only decides whether the tags can be emitted safely.
 */
class FrontendAssets
{
    /**
     * @param  list<string>  $entries
     */
    public static function viteEntriesBuilt(array $entries): bool
    {
        if ($entries === []) {
            return false;
        }

        // The Vite dev server serves every entry itself, so a hot file is
        // enough — there is no manifest while it runs.
        if (is_file(public_path('hot'))) {
            return true;
        }

        // Laravel's default build directory. A consumer who has moved it with
        // Vite::useBuildDirectory() has built their assets by definition, and
        // the worst case here is a correctly-built page rendering unstyled
        // rather than a 500.
        $manifest = public_path('build/manifest.json');

        if (!is_file($manifest)) {
            return false;
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);

        if (!is_array($decoded)) {
            return false;
        }

        foreach ($entries as $entry) {
            if (!isset($decoded[$entry])) {
                return false;
            }
        }

        return true;
    }
}
