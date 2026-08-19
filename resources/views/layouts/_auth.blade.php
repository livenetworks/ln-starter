<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>@yield('title', config('app.name'))</title>

		{{-- Emitted only when the application has built these entries. @vite()
		     throws otherwise, which would turn a missing `npm run build` into a
		     500 on the entry point of the login flow. --}}
		@php($lnAuthAssets = ['resources/scss/auth.scss', 'resources/js/app.js'])

		@if (\LiveNetworks\LnStarter\Support\FrontendAssets::viteEntriesBuilt($lnAuthAssets))
			@vite($lnAuthAssets)
		@endif

		@stack('styles')
	</head>
	<body>
		<x-ln.lang-switcher />

		@yield('content')

		<x-ln.toast />

		@stack('scripts')
	</body>
</html>
