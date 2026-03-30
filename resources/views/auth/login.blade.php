@extends(config('ln-starter.auth.layout', 'layouts._auth'))

@section('title', __('Login'))

@section('content')
<div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50 px-4 py-12">
	<div class="w-full max-w-md">
		<!-- Logo/Brand Section -->
		<div class="text-center mb-8">
			<div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-2xl shadow-lg mb-4">
				<svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
				</svg>
			</div>
			<h1 class="text-3xl font-bold text-gray-900 mb-2">{{ config('app.name') }}</h1>
			<p class="text-gray-600">{{ __('Sign in to your account') }}</p>
		</div>

		<!-- Login Card -->
		<div class="bg-white rounded-2xl shadow-xl overflow-hidden">
			<div class="p-8">
				<div class="mb-6">
					<h2 class="text-2xl font-bold text-gray-900 mb-2">{{ __('Welcome back') }}</h2>
					<p class="text-gray-600">{{ __('Enter your email to receive a magic link') }}</p>
				</div>

				<form method="post" action="{{ route('login.magic-link') }}" class="space-y-6" novalidate>
					@csrf

					<div class="space-y-2">
						<label for="magic-email" class="block text-sm font-semibold text-gray-700">
							{{ __('Email address') }}
						</label>
						<div class="relative">
							<div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
								<svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
								class="block w-full pl-10 pr-3 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all duration-200 text-gray-900 placeholder-gray-400 bg-gray-50 focus:bg-white"
								placeholder="name@example.com"
								value="{{ old('email') }}"
							>
						</div>
						<p class="text-xs text-gray-500 flex items-start mt-2">
							<svg class="w-4 h-4 mr-1 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
								<path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
							</svg>
							{{ __("We'll send you a secure login link to your email") }}
						</p>
					</div>

					<button
						type="submit"
						class="w-full flex items-center justify-center px-4 py-3 border border-transparent text-base font-semibold rounded-lg text-white bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transform transition-all duration-200 hover:scale-[1.02] active:scale-[0.98] shadow-lg hover:shadow-xl"
					>
						<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
						</svg>
						{{ __('Send magic link') }}
					</button>
				</form>
			</div>

			<!-- Footer Section -->
			<div class="px-8 py-4 bg-gray-50 border-t border-gray-100">
				<div class="flex items-center justify-center text-xs text-gray-500">
					<svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
					</svg>
					{{ __('Secure passwordless authentication') }}
				</div>
			</div>
		</div>

		<!-- Terms Section -->
		<p class="mt-8 text-center text-sm text-gray-600">
			{{ __('By continuing, you agree to our') }}
			<a href="#" class="font-medium text-blue-600 hover:text-blue-500 transition-colors">{{ __('Terms of Service') }}</a>
			{{ __('and') }}
			<a href="#" class="font-medium text-blue-600 hover:text-blue-500 transition-colors">{{ __('Privacy Policy') }}</a>
		</p>
	</div>
</div>
@endsection
