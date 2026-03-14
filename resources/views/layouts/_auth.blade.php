<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<title>@yield('title', config('app.name'))</title>
		@stack('styles')
	</head>
	<body class="auth-layout">
		<main class="auth-main">
			@yield('content')
		</main>

		<footer class="auth-footer">
			&copy; {{ date('Y') }} {{ config('app.name') }}
		</footer>

		@stack('scripts')
	</body>
</html>
