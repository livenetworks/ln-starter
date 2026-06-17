<?php

namespace LiveNetworks\LnStarter\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use LiveNetworks\LnStarter\Support\LocaleManager;
use Symfony\Component\HttpFoundation\Response;

class RedirectToLocale
{
	public function __construct(protected LocaleManager $locale) {}

	public function handle(Request $request, Closure $next): Response
	{
		// Single-language app: never redirect, just lock the app locale.
		if (!$this->locale->multilingual()) {
			app()->setLocale($this->locale->fallback());
			return $next($request);
		}

		$segments = explode('/', trim($request->path(), '/'));
		$first    = $segments[0] ?? '';

		// Already locale-prefixed → continue.
		if ($first !== '' && in_array($first, $this->locale->supported(), true)) {
			return $next($request);
		}

		$target = $this->locale->negotiate($request);
		$clean  = trim($request->path(), '/');
		$query  = $request->getQueryString();

		$url = '/' . $target . ($clean !== '' ? '/' . $clean : '');
		if ($query) {
			$url .= '?' . $query;
		}

		return redirect($url, 302);
	}
}
