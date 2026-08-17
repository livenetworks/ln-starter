@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Enter sign-in code'))

@section('content')
<div class="auth-page">
	<div class="auth-page__inner">
		<div class="auth-card">
			<div class="auth-card__body">
				<h2 class="auth-card__title">{{ __('Check your email') }}</h2>
				<p class="auth-card__subtitle">
					{{ __('If an eligible account exists, a sign-in link and code have been sent.') }}
				</p>

				<form method="POST" action="{{ route('auth.magic.code') }}" class="auth-form" novalidate>
					@csrf
					<div class="auth-form__group">
						<label for="magic-code" class="auth-form__label">{{ __('Six-digit code') }}</label>
						<input
							id="magic-code"
							name="code"
							type="text"
							inputmode="numeric"
							autocomplete="one-time-code"
							pattern="[0-9]{6}"
							maxlength="6"
							required
							class="auth-form__input"
						>
					</div>

					<button type="submit" class="auth-form__button">{{ __('Sign in with code') }}</button>
				</form>

				<p class="auth-form__hint">
					{{ __('If you opened the email on this device, you can choose the link instead. Confirming the link invalidates this code.') }}
				</p>
			</div>
		</div>
	</div>
</div>
@endsection
