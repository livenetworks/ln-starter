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
        $waitView = $viewsPath . '/auth/magic_wait.blade.php';
        if (is_file($waitView)) {
            $findings[] = $waitView;
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
