@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Login'))

@section('content')
	<div class="auth-content">
		<div class="auth-card">
			<h2 class="auth-card__title">{{ __('Login') }}</h2>

			<form method="post" action="{{ route('login.magic-link') }}" class="auth-form" novalidate>
				@csrf
				<div>
					<label for="magic-email" class="auth-form__label">{{ __('E-mail address') }}</label>
					<input id="magic-email" name="email" type="email" required autocomplete="email" inputmode="email"
						   class="auth-form__input" placeholder="name@example.com" value="{{ old('email') }}">
					<p class="auth-form__hint">{{ __('If the address is registered, you will receive a magic link for login.') }}</p>
				</div>
				<button type="submit" class="auth-form__button">{{ __('Send magic link') }}</button>
			</form>
		</div>

		<p class="auth-disclaimer auth-disclaimer--spaced">{{ __('By logging in you agree to the terms of use.') }}</p>
	</div>
@endsection
