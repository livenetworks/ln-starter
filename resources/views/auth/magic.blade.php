@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Confirm sign in'))

@section('content')
<div class="auth-page">
	<div class="auth-page__inner">
		<div class="auth-card">
			<div class="auth-card__body auth-card__body--centered">
				@if ($valid)
					<div class="auth-icon auth-icon--green">
						<div class="auth-icon__circle" aria-hidden="true">✓</div>
					</div>

					<h2 class="auth-status__title">{{ __('Sign in on this device') }}</h2>
					<p class="auth-status__text">
						{{ __('Confirming signs in this browser and invalidates the six-digit code for the requesting device.') }}
					</p>

					<form method="POST" action="{{ route('auth.magic.link.consume', ['context' => $context]) }}">
						@csrf
						<button type="submit" class="auth-form__button">
							{{ __('Sign in on this device') }}
						</button>
					</form>

					<a href="{{ route('login') }}" class="auth-card__footer-link">
						{{ __('Cancel and use the code on the requesting device') }}
					</a>
				@else
					<div class="auth-icon auth-icon--red">
						<div class="auth-icon__circle" aria-hidden="true">!</div>
					</div>

					<h2 class="auth-status__title">{{ __('Sign in failed') }}</h2>
					<p class="auth-status__text">
						{{ __('The sign-in proof is invalid or expired. Request a new email and try again.') }}
					</p>

					<a href="{{ route('login') }}" class="auth-form__button">
						{{ __('Request a new email') }}
					</a>
				@endif
			</div>
		</div>
	</div>
</div>
@endsection
