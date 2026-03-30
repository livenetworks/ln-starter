@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Success'))

@section('content')
<div class="auth-page">
	<div class="auth-page__inner">
		<div class="auth-card">
			<div class="auth-card__body auth-card__body--centered">
				<!-- Success icon -->
				<div class="auth-icon auth-icon--green">
					<div class="auth-icon__circle">
						<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
						</svg>
					</div>
					<div class="auth-icon__badge auth-icon__badge--bounce">
						<svg fill="currentColor" viewBox="0 0 20 20">
							<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
						</svg>
					</div>
				</div>

				<h2 class="auth-status__title">{{ __('Login successful!') }}</h2>
				<p class="auth-status__text auth-status__text--tight">
					{{ __('This window will close automatically.') }}
				</p>

				<!-- Countdown -->
				<div class="auth-alert auth-alert--success auth-alert--spaced">
					<div class="auth-alert__row auth-alert__row--centered">
						<svg class="auth-alert__check" fill="currentColor" viewBox="0 0 20 20">
							<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
						</svg>
						<span id="countdown-text">{{ __('Closing in') }} <span id="countdown">3</span> {{ __('seconds') }}…</span>
					</div>
				</div>

				<button onclick="window.close()" class="auth-form__button auth-form__button--green">
					{{ __('Close this window') }}
					<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
					</svg>
				</button>
			</div>
		</div>
	</div>
</div>
@endsection

@push('scripts')
	<script>
		let seconds = 3;
		const countdownEl = document.getElementById('countdown');

		const countdown = setInterval(() => {
			seconds--;
			if (countdownEl) countdownEl.textContent = seconds;

			if (seconds <= 0) {
				clearInterval(countdown);
				window.close();
				setTimeout(() => {
					if (!window.closed) {
						const el = document.getElementById('countdown-text');
						if (el) el.textContent = '{{ __('You can safely close this window now') }}';
					}
				}, 500);
			}
		}, 1000);
	</script>
@endpush
