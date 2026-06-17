@php
	use Illuminate\Support\Facades\Route;
	use LiveNetworks\LnStarter\Support\LocaleManager;

	$languages = config('app.languages', []);
	$locale    = app(LocaleManager::class);
	$routeName = Route::currentRouteName();
	$current   = app()->getLocale();
	$params    = Route::current()?->parameters() ?? [];
@endphp

@if ($locale->multilingual() && $routeName)
	<details class="lang-switcher">
		<summary class="lang-switcher__summary" aria-label="{{ __('Language') }}" aria-current="true">
			<span class="lang-switcher__flag" aria-hidden="true">
				@include('ln-starter::components.ln.partials.lang-flag', ['code' => $current])
			</span>
			<span class="lang-switcher__code">{{ strtoupper($current) }}</span>
			<svg class="lang-switcher__caret" viewBox="0 0 12 12" aria-hidden="true">
				<path d="M2.5 4.5 6 8l3.5-3.5" fill="none" stroke="currentColor"
					stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
			</svg>
		</summary>

		<ul class="lang-switcher__list">
			@foreach ($languages as $code => $label)
				@continue($code === $current)
				<li class="lang-switcher__item">
					<a class="lang-switcher__link"
						href="{{ route($routeName, array_merge($params, ['locale' => $code])) }}"
						lang="{{ $code }}"
						hreflang="{{ $code }}">
						<span class="lang-switcher__flag" aria-hidden="true">
							@include('ln-starter::components.ln.partials.lang-flag', ['code' => $code])
						</span>
						<span class="lang-switcher__label">{{ $label }}</span>
					</a>
				</li>
			@endforeach
		</ul>
	</details>
@endif
