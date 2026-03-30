@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Success'))

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 px-4 py-12">
	<div class="w-full max-w-md">
		<div class="bg-white rounded-2xl shadow-xl overflow-hidden">
			<div class="p-8 text-center">
				<!-- Success Icon -->
				<div class="relative inline-block mb-6">
					<div class="w-20 h-20 bg-gradient-to-br from-green-100 to-emerald-100 rounded-full flex items-center justify-center">
						<svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
						</svg>
					</div>
					<div class="absolute -top-1 -right-1 w-8 h-8">
						<svg class="w-8 h-8 text-green-500 animate-bounce" fill="currentColor" viewBox="0 0 20 20">
							<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
						</svg>
					</div>
				</div>

				<!-- Success Message -->
				<h2 class="text-2xl font-bold text-gray-900 mb-3">{{ __('Login successful!') }}</h2>
				<p class="text-gray-600 mb-4">
					{{ __('This window will close automatically.') }}
				</p>

				<!-- Auto-close countdown -->
				<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
					<div class="flex items-center justify-center text-green-800">
						<svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
							<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
						</svg>
						<span class="text-sm font-medium">{{ __('Closing in') }} <span id="countdown">3</span> {{ __('seconds') }}…</span>
					</div>
				</div>

				<!-- Manual close button -->
				<button onclick="window.close()" class="inline-flex items-center justify-center w-full px-6 py-3 border border-transparent text-base font-semibold rounded-lg text-white bg-gradient-to-r from-green-600 to-emerald-600 hover:from-green-700 hover:to-emerald-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transform transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] shadow-lg hover:shadow-xl">
					{{ __('Close this window') }}
					<svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
					if (!window.closed && countdownEl) {
						countdownEl.parentElement.innerHTML = '<span class="text-sm font-medium">{{ __('You can safely close this window now') }}</span>';
					}
				}, 500);
			}
		}, 1000);
	</script>
@endpush
