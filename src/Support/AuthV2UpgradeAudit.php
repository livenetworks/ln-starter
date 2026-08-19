<?php

namespace LiveNetworks\LnStarter\Support;

class AuthV2UpgradeAudit
{
    /** @return array<int, string> */
    public function legacyPublishedViews(?string $viewsPath = null): array
    {
        $viewsPath ??= resource_path('views/vendor/ln-starter');

        if (!is_dir($viewsPath)) {
            return [];
        }

        $findings = [];

        // Views auth v2 removed outright. Detected by name, because a
        // published copy need not contain anything recognisable: the
        // original magic_success.blade.php references no removed route and
        // no legacy field, so the content scan below would never see it and
        // it would sit in the consumer tree forever as dead state.
        //
        // magic_wait polls an endpoint that is now a tombstone; magic_success
        // is orphaned, since v2 has no success page. Neither can be ported,
        // so both are removals.
        foreach (['magic_wait', 'magic_success'] as $removed) {
            $path = $viewsPath . '/auth/' . $removed . '.blade.php';

            if (is_file($path)) {
                $findings[] = $path;
            }
        }

        $legacyPatterns = [
            'auth.magic.consume',
            'auth.magic.show',
            '/magic/status',
            'magic_link_user_id',
            'magic_link_token_id',
            'auth_token',
        ];

        foreach (glob($viewsPath . '/{auth,emails}/*.blade.php', GLOB_BRACE) ?: [] as $view) {
            $contents = file_get_contents($view);
            if ($contents === false) {
                continue;
            }

            foreach ($legacyPatterns as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $findings[] = $view;
                    break;
                }
            }

            if (basename($view) === 'magic-link.blade.php' && !str_contains($contents, '$code')) {
                $findings[] = $view;
            }
        }

        $findings = array_values(array_unique($findings));
        sort($findings);

        return $findings;
    }
}
