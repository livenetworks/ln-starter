<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use LiveNetworks\LnStarter\Support\LocaleManager;
use Symfony\Component\HttpFoundation\Response;

class PrepareLocale
{
	public function __construct(protected LocaleManager $locale) {}

	public function handle(Request $request, Closure $next): Response
	{
		// Seed URL::defaults so route() helpers always have a locale default,
		// even outside the {locale} prefix group (e.g. 401 redirects, magic-link emails).
		$negotiated = $this->locale->negotiate($request);
		URL::defaults(['locale' => $negotiated]);

		// Single-language apps: lock the app locale now; no redirect will follow.
		if (!$this->locale->multilingual()) {
			app()->setLocale($this->locale->fallback());
		}

		return $next($request);
	}
}
