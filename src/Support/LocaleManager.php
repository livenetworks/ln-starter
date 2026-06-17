<?php

namespace LiveNetworks\LnStarter\Support;

use Illuminate\Http\Request;

class LocaleManager
{
	public function supported(): array
	{
		$keys = array_keys(config('app.languages', []));

		if (!empty($keys)) {
			return $keys;
		}

		// Mirror SetLocale's fallback when no languages configured
		$fallbacks = array_values(array_unique(array_filter([
			config('app.locale'),
			config('app.fallback_locale'),
		])));

		if (!empty($fallbacks)) {
			return $fallbacks;
		}

		return ['en'];
	}

	public function isSupported(string $locale): bool
	{
		$primary = $this->primarySubtag($locale);
		foreach ($this->supported() as $key) {
			if ($this->primarySubtag($key) === $primary) {
				return true;
			}
		}
		return false;
	}

	public function fallback(): string
	{
		return config('app.fallback_locale')
			?: config('app.locale')
			?: ($this->supported()[0] ?? 'en');
	}

	public function multilingual(): bool
	{
		return count($this->supported()) > 1;
	}

	public function negotiate(Request $request): string
	{
		// (a) Explicit URL locale segment — authoritative
		$route = $request->route('locale');
		if (is_string($route) && ($m = $this->matchSupported($route))) {
			return $m;
		}

		// (b) Sticky session locale
		if ($request->hasSession()) {
			$session = $request->session()->get('locale');
			if (is_string($session) && ($m = $this->matchSupported($session))) {
				return $m;
			}
		}

		// (c) Accept-Language header (Symfony returns them q-ordered)
		foreach ($request->getLanguages() as $tag) {
			if ($m = $this->matchSupported($tag)) {
				return $m;
			}
		}

		// (d) Configured fallback
		return $this->fallback();
	}

	protected function primarySubtag(string $locale): string
	{
		return strtolower(explode('-', str_replace('_', '-', $locale))[0]);
	}

	/** Map an arbitrary tag to the exact supported key, or null. */
	protected function matchSupported(string $locale): ?string
	{
		$primary = $this->primarySubtag($locale);
		foreach ($this->supported() as $key) {
			if ($this->primarySubtag($key) === $primary) {
				return $key;
			}
		}
		return null;
	}
}
