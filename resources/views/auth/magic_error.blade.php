@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Error'))

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 px-4 py-12">
	<div class="w-full max-w-md">
		<div class="bg-white rounded-2xl shadow-xl overflow-hidden">
			<div class="p-8 text-center">
				<!-- Error Icon -->
				<div class="relative inline-block mb-6">
					<div class="w-20 h-20 bg-gradient-to-br from-red-100 to-orange-100 rounded-full flex items-center justify-center">
						<svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
						</svg>
					</div>
				</div>

				<!-- Error Message -->
				<h2 class="text-2xl font-bold text-gray-900 mb-3">{{ __('Problem with the link') }}</h2>
				<p class="text-gray-600 mb-6">
					{{ $message ?? __('Link is invalid or expired.') }}
				</p>

				<!-- Error Details -->
				<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6 text-left">
					<div class="flex">
						<svg class="w-5 h-5 text-red-400 mr-3 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
							<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
						</svg>
						<div class="text-sm text-red-700">
							<p class="font-medium mb-2">{{ __('Common reasons:') }}</p>
							<ul class="space-y-1 text-xs">
								<li>• {{ __('The link has expired (valid for :minutes minutes)', ['minutes' => config('ln-starter.auth.token_expiry', 15)]) }}</li>
								<li>• {{ __('The link was already used') }}</li>
								<li>• {{ __('The link was not copied correctly') }}</li>
							</ul>
						</div>
					</div>
				</div>

				<!-- Back to Login Button -->
				<a href="{{ route('login') }}" class="inline-flex items-center justify-center w-full px-6 py-3 border border-transparent text-base font-semibold rounded-lg text-white bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transform transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] shadow-lg hover:shadow-xl">
					<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
					</svg>
					{{ __('Request a new link') }}
				</a>
			</div>
		</div>
	</div>
</div>
@endsection
