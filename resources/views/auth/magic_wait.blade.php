@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Confirm login'))

@section('content')
	<div class="auth-main">
		<div class="auth-card">
			<div class="auth-card__body auth-content--centered">
				{{-- Animated email icon --}}
				<div class="auth-status__icon auth-status__icon--pulse">
					<div class="auth-status__icon__circle">
						<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
						</svg>
					</div>
					<div class="auth-status__icon-badge">
						<svg fill="currentColor" viewBox="0 0 20 20">
							<path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
						</svg>
					</div>
				</div>

				<h2 class="auth-status__title">{{ __('Check your email') }}</h2>
				<p class="auth-status__text">
					{{ __('We sent a login link to your email. Open it on any device and we\'ll automatically log you in here.') }}
				</p>

				{{-- Status indicator --}}
				<div id="state" class="auth-status__state">
					<svg class="auth-status__spinner" fill="none" viewBox="0 0 24 24">
						<circle opacity=".25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
						<path opacity=".75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
					</svg>
					<span>{{ __('Waiting for confirmation') }}…</span>
				</div>

				{{-- Timeout message --}}
				<div id="timeout-message" class="auth-alert auth-alert--error auth-alert--hidden">
					<div class="auth-alert__row">
						<div class="auth-alert__icon">
							<svg fill="currentColor" viewBox="0 0 20 20">
								<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
							</svg>
						</div>
						<div class="auth-alert__body">
							<p class="auth-alert__title">{{ __('Time expired') }}</p>
							<p>{{ __('The link is valid for :minutes minutes.', ['minutes' => config('ln-starter.auth.token_expiry', 15)]) }}</p>
							<a href="{{ route('login') }}" class="auth-alert__link">
								<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
								</svg>
								{{ __('Back to login') }}
							</a>
						</div>
					</div>
				</div>

				{{-- Info box --}}
				<div class="auth-alert auth-alert--info">
					<div class="auth-alert__row">
						<div class="auth-alert__icon">
							<svg fill="currentColor" viewBox="0 0 20 20">
								<path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
							</svg>
						</div>
						<div class="auth-alert__body">
							<p class="auth-alert__title">{{ __('Having trouble?') }}</p>
							<ul class="auth-alert__list">
								<li>{{ __('Check your spam or junk folder') }}</li>
								<li>{{ __('Make sure you entered the correct email') }}</li>
								<li>{{ __('The link expires in :minutes minutes', ['minutes' => config('ln-starter.auth.token_expiry', 15)]) }}</li>
							</ul>
						</div>
					</div>
				</div>
			</div>

			<div class="auth-card__footer">
				<a href="{{ route('login') }}">
					<svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
					</svg>
					{{ __('Use a different email') }}
				</a>
			</div>
		</div>
	</div>
@endsection

@push('scripts')
	<script>
		const stateEl = document.getElementById('state');
		const timeoutMsg = document.getElementById('timeout-message');
		const loginUrl = '{{ route('login') }}';
		const MAX_ATTEMPTS = {{ config('ln-starter.auth.token_expiry', 15) * 30 }};
		let attempts = 0;

		const showTimeout = () => {
			stateEl.textContent = '{{ __('Waiting time expired.') }}';
			timeoutMsg.classList.remove('auth-alert--hidden');
			setTimeout(() => { window.location.href = loginUrl; }, 3000);
		};

		const poll = async () => {
			attempts++;

			if (attempts > MAX_ATTEMPTS) {
				showTimeout();
				return;
			}

			if (attempts % 30 === 0) {
				const minutesLeft = Math.ceil((MAX_ATTEMPTS - attempts) * 2 / 60);
				stateEl.querySelector('span').textContent = `{{ __('Waiting for confirmation') }}… (~${minutesLeft} min)`;
			}

			try {
				const r = await fetch('{{ route('magic.status') }}', {
					credentials: 'include',
					headers: { 'X-Requested-With': 'XMLHttpRequest' }
				});
				const j = await r.json();

				if (j.ok) {
					stateEl.textContent = '{{ __('Success! Redirecting') }}…';
					window.location.href = j.redirect || '/';
					return;
				}

				if (j.error === 'Token expired' || j.error === 'No session') {
					showTimeout();
					return;
				}
			} catch(e) {
				console.error('Polling error:', e);
				stateEl.querySelector('span').textContent = '{{ __('Connection problem, trying again') }}…';
			}

			setTimeout(poll, 2000);
		};

		poll();
	</script>
@endpush
