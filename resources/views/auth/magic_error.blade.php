@extends(config('ln-starter.auth.layout', 'ln-starter::layouts._auth'))

@section('title', __('Error'))

@section('content')
	<div class="auth-content auth-content--centered">
		<h2 class="auth-status__title">{{ __('Problem with the link') }}</h2>
		<p class="auth-status__text">
			{{ $message ?? __('Link is invalid or expired.') }}
		</p>

		<a href="{{ route('login') }}" class="btn-primary">
			{{ __('Back to login') }}
		</a>
	</div>
@endsection
