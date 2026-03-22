<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>@yield('title', config('app.name'))</title>

		@vite([
			'resources/scss/auth.scss',
			'resources/js/app.js'
		])

		@stack('styles')
	</head>
	<body class="auth-layout">
		<header class="auth-header">
			@yield('logo')
			<h1 class="auth-header__title">
				{{ config('app.name') }}
			</h1>
		</header>

		<main class="auth-main">
			@yield('content')
		</main>

		<footer class="auth-footer">
			&copy; {{ date('Y') }} Powered by <a href="https://livenetworks.mk" target="_blank" rel="noopener noreferrer">Live Networks</a>
		</footer>

		<x-ln.toast />

		@stack('scripts')
	</body>
</html>
