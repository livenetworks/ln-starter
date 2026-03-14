@extends(config('ln-starter.auth.layout', 'ln-starter::layouts._auth'))

@section('title', __('Success'))

@section('content')
	<div class="auth-content auth-content--centered">
		<h2 class="auth-status__title">{{ __('Login successful!') }}</h2>
		<p class="auth-status__text">
			{{ __('You can close this window and continue on your computer.') }}
		</p>

		<a href="/" class="btn-primary">
			{{ __('Continue to the application') }}
		</a>
	</div>
@endsection
