@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Sign In'))

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
		</div>

		<!-- Card -->
		<div class="auth-card">
			<div class="auth-card__body auth-card__body--centered">

				@if ($magicToken && $magicToken->isValid())
					<!-- Valid token — confirmation -->
					<div class="auth-icon auth-icon--green">
						<div class="auth-icon__circle">
							<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
							</svg>
						</div>
					</div>

					<h2 class="auth-status__title">{{ __('Confirm sign in') }}</h2>
					<p class="auth-status__text">{{ __('Click below to complete your login.') }}</p>

					<form method="POST" action="{{ route('auth.magic.consume', ['token' => $token]) }}">
						@csrf
						<button type="submit" class="auth-form__button">
							<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
							</svg>
							{{ __('Sign in') }}
						</button>
					</form>
				@else
					<!-- Invalid / expired token -->
					<div class="auth-icon auth-icon--red">
						<div class="auth-icon__circle">
							<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
							</svg>
						</div>
					</div>

					<h2 class="auth-status__title">{{ __('Problem with the link') }}</h2>
					<p class="auth-status__text">
						{{ __('Link is invalid or expired.') }}
					</p>

					<div class="auth-alert auth-alert--error auth-alert--spaced">
						<div class="auth-alert__row">
							<div class="auth-alert__icon">
								<svg fill="currentColor" viewBox="0 0 20 20">
									<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
								</svg>
							</div>
							<div class="auth-alert__content">
								<p class="auth-alert__title">{{ __('Common reasons:') }}</p>
								<ul class="auth-alert__list">
									<li>{{ __('The link has expired (valid for :minutes minutes)', ['minutes' => config('ln-starter.auth.token_expiry', 15)]) }}</li>
									<li>{{ __('The link was already used') }}</li>
									<li>{{ __('The link was not copied correctly') }}</li>
								</ul>
							</div>
						</div>
					</div>

					<a href="{{ route('login') }}" class="auth-form__button">
						<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
						</svg>
						{{ __('Request a new link') }}
					</a>
				@endif

			</div>
		</div>
	</div>
</div>
@endsection
