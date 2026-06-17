@php
	use Illuminate\Support\Facades\Route;
	use LiveNetworks\LnStarter\Support\LocaleManager;

	$languages   = config('app.languages', []);
	$locale      = app(LocaleManager::class);
	$routeName   = Route::currentRouteName();
	$current     = app()->getLocale();
	$params      = Route::current()?->parameters() ?? [];
@endphp

@if ($locale->multilingual() && $routeName)
	<nav class="lang-switcher" aria-label="{{ __('Language') }}">
		<ul class="lang-switcher__list">
			@foreach ($languages as $code => $label)
				<li class="lang-switcher__item">
					@if ($code === $current)
						<span class="lang-switcher__current" lang="{{ $code }}" aria-current="true">{{ $label }}</span>
					@else
						<a class="lang-switcher__link"
							href="{{ route($routeName, array_merge($params, ['locale' => $code])) }}"
							lang="{{ $code }}"
							hreflang="{{ $code }}">{{ $label }}</a>
					@endif
				</li>
			@endforeach
		</ul>
	</nav>
@endif
