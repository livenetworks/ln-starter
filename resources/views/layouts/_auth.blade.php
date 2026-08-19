<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>@yield('title', config('app.name'))</title>

		{{-- Rendered through the application's own Vite service, so a custom
		     hot file, build directory or manifest name is honoured. Emits
		     nothing when the entries have not been built, rather than
		     throwing on the entry point of the login flow. --}}
		{!! \LiveNetworks\LnStarter\Support\FrontendAssets::viteTags([
			'resources/scss/auth.scss',
			'resources/js/app.js',
		]) !!}

		@stack('styles')
	</head>
	<body>
		<x-ln.lang-switcher />

		@yield('content')

		<x-ln.toast />

		@stack('scripts')
	</body>
</html>
