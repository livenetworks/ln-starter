<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>@yield('title', config('app.name'))</title>

		@vite([
			'resources/scss/app.scss',
			'resources/js/app.js'
		])

		@stack('styles')
	</head>
	<body>
		<header>
			@yield('header')
		</header>

		<main>
			@yield('content')
		</main>

		<footer>
			@yield('footer')
		</footer>

		<x-ln.toast />


		@stack('scripts')
	</body>
</html>
