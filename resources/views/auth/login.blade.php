@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Login'))

@section('content')
<div class="auth-page">
	<div class="auth-page__inner">
		<!-- Brand -->
		<div class="auth-header">
			<div class="auth-header__logo">
				<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
				</svg>
			</div>
			<h1 class="auth-header__title">{{ config('app.name') }}</h1>
			<p class="auth-header__subtitle">{{ __('Sign in to your account') }}</p>
		</div>

		<!-- Card -->
		<div class="auth-card">
			<div class="auth-card__body">
				<h2 class="auth-card__title">{{ __('Welcome back') }}</h2>
				<p class="auth-card__subtitle">{{ __('Enter your email to receive a magic link') }}</p>

				<form method="post" action="{{ route('login.magic-link') }}" class="auth-form" novalidate>
					@csrf

					<div class="auth-form__group">
						<label for="magic-email" class="auth-form__label">
							{{ __('Email address') }}
						</label>
						<div class="auth-form__input-wrap">
							<div class="auth-form__input-icon">
								<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207" />
								</svg>
							</div>
							<input
								id="magic-email"
								name="email"
								type="email"
								required
								autocomplete="email"
								inputmode="email"
								class="auth-form__input"
								placeholder="name@example.com"
								value="{{ old('email') }}"
							>
						</div>
						<p class="auth-form__hint">
							<svg fill="currentColor" viewBox="0 0 20 20">
								<path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
							</svg>
							{{ __("We'll send you a secure login link to your email") }}
						</p>
					</div>

					<button type="submit" class="auth-form__button">
						<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
						</svg>
						{{ __('Send magic link') }}
					</button>
				</form>
			</div>

			<div class="auth-card__footer">
				<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
				</svg>
				{{ __('Secure passwordless authentication') }}
			</div>
		</div>

		<!-- Terms -->
		<p class="auth-disclaimer">
			{{ __('By continuing, you agree to our') }}
			<a href="#" class="auth-disclaimer__link">{{ __('Terms of Service') }}</a>
			{{ __('and') }}
			<a href="#" class="auth-disclaimer__link">{{ __('Privacy Policy') }}</a>
		</p>
	</div>
</div>
@endsection
