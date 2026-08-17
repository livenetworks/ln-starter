<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="referrer" content="no-referrer">
	<title>{{ __(config('ln-starter.auth.mail_subject', 'Magic Link Login')) }}</title>
</head>
<body style="font-family:Arial,sans-serif;line-height:1.6;color:#222;background:#f5f5f5;padding:20px">
	<div style="max-width:600px;margin:auto;background:white;border-radius:8px;padding:32px">
		<h1 style="font-size:24px">{{ __(config('ln-starter.auth.mail_subject', 'Magic Link Login')) }}</h1>
		<p>{{ __('Choose exactly one way to sign in:') }}</p>

		<h2 style="font-size:18px">{{ __('Sign in on the device reading this email') }}</h2>
		<p>{{ __('Opening the link is safe. You must confirm once more before this device is signed in.') }}</p>
		<p>
			<a href="{{ $link }}" rel="noreferrer" style="display:inline-block;padding:12px 20px;background:#4f46e5;color:white;text-decoration:none;border-radius:6px">
				{{ __('Sign in on this device') }}
			</a>
		</p>

		<h2 style="font-size:18px">{{ __('Sign in on the device that requested the email') }}</h2>
		<p>{{ __('Enter this code in the requesting browser:') }}</p>
		<p style="font-size:32px;font-weight:bold;letter-spacing:8px">{{ $code }}</p>

		<p>{{ __('Confirming the link invalidates the code, and using the code invalidates the link.') }}</p>
		<p>{{ __('Both options expire in :minutes minutes and can be used only once.', ['minutes' => $expiresIn]) }}</p>
		<p>{{ __('If you did not request this email, ignore it.') }}</p>
	</div>
</body>
</html>
