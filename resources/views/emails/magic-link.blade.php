<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>{{ __(config('ln-starter.auth.mail_subject', 'Magic Link Login')) }}</title>
	<style>
		body {
			font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
			line-height: 1.6;
			color: #333;
			max-width: 600px;
			margin: 0 auto;
			padding: 20px;
			background-color: #f5f5f5;
		}
		.container {
			background-color: #ffffff;
			border-radius: 8px;
			padding: 40px;
			box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
		}
		.header {
			text-align: center;
			margin-bottom: 30px;
		}
		.header h1 {
			color: #4f46e5;
			margin: 0;
			font-size: 24px;
		}
		.content {
			margin-bottom: 30px;
		}
		.button {
			display: inline-block;
			padding: 14px 28px;
			background-color: #4f46e5;
			color: #ffffff !important;
			text-decoration: none;
			border-radius: 6px;
			font-weight: 600;
			text-align: center;
		}
		.button:hover {
			background-color: #4338ca;
		}
		.button-container {
			text-align: center;
			margin: 30px 0;
		}
		.footer {
			margin-top: 30px;
			padding-top: 20px;
			border-top: 1px solid #e5e7eb;
			font-size: 14px;
			color: #6b7280;
			text-align: center;
		}
		.expiry {
			font-size: 14px;
			color: #6b7280;
			margin-top: 20px;
		}
		.alternative-link {
			margin-top: 20px;
			padding: 15px;
			background-color: #f9fafb;
			border-radius: 6px;
			word-break: break-all;
			font-size: 12px;
			color: #6b7280;
		}
	</style>
</head>
<body>
	<div class="container">
		<div class="header">
			<h1>{{ __(config('ln-starter.auth.mail_subject', 'Magic Link Login')) }}</h1>
		</div>

		<div class="content">
			<p>{{ __('Hello') }},</p>
			<p>{{ __('A login was requested for your account. Click the button below to sign in:') }}</p>
		</div>

		<div class="button-container">
			<a href="{{ $link }}" class="button">{{ __('Sign in') }}</a>
		</div>

		<div class="expiry">
			<strong>{{ __('Important:') }}</strong> {{ __('This link expires at :time and can only be used once.', ['time' => $expiresAt]) }}
		</div>

		<div class="alternative-link">
			<p><strong>{{ __("Can't click the button?") }}</strong></p>
			<p>{{ __('Copy and paste this link into your browser:') }}</p>
			<p>{{ $link }}</p>
		</div>

		<div class="footer">
			<p>{{ __('If you did not request this link, ignore this email.') }}</p>
			<p>&copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}</p>
		</div>
	</div>
</body>
</html>
