@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Confirm login'))

@section('content')
	<div class="auth-content auth-content--centered">
		<span class="auth-status__icon ln-icon-envelope ln-icon--xl" aria-hidden="true"></span>

		<h2 class="auth-status__title">{{ __('Check email') }}</h2>
		<p class="auth-status__text">
			{{ __('We sent a login link. Open it on your phone or another device and we will continue automatically here.') }}
		</p>

		<div id="state" class="auth-status__state">{{ __('Waiting for confirmation') }}…</div>

		<div id="timeout-message" class="auth-status__timeout">
			<p>{{ __('Time expired. The link is valid for 15 more minutes.') }}</p>
			<a href="{{ route('login') }}">{{ __('Back to login') }}</a>
		</div>
	</div>
@endsection

@push('scripts')
	<script>
		const stateEl = document.getElementById('state');
		const timeoutMsg = document.getElementById('timeout-message');
		const MAX_ATTEMPTS = 150; // 5 minutes (150 × 2 seconds)
		let attempts = 0;

		const poll = async () => {
			attempts++;

			if (attempts > MAX_ATTEMPTS) {
				stateEl.textContent = '{{ __('Waiting time expired.') }}';
				timeoutMsg.style.display = 'block';
				return;
			}

			if (attempts % 30 === 0) {
				const minutesLeft = Math.ceil((MAX_ATTEMPTS - attempts) * 2 / 60);
				stateEl.textContent = `{{ __('Waiting for confirmation') }}… (~${minutesLeft} min)`;
			}

			try {
				const r = await fetch('{{ route('magic.status') }}', {
					credentials: 'include',
					headers: { 'X-Requested-With': 'XMLHttpRequest' }
				});
				const j = await r.json();

				if (j.ok) {
					stateEl.textContent = '{{ __('Success! Redirecting') }}…';
					if (j.token) {
						sessionStorage.setItem('auth_token', j.token);
					}
					window.location.href = j.redirect || '/';
					return;
				}
			} catch(e) {
				console.error('Polling error:', e);
				stateEl.textContent = '{{ __('Connection problem, trying again') }}…';
			}

			setTimeout(poll, 2000);
		};

		poll();
	</script>
@endpush
