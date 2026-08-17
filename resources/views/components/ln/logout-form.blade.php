<form method="POST" action="{{ route('logout') }}" {{ $attributes }}>
	@csrf
	<button type="submit" @class([$buttonClass => $buttonClass !== ''])>
		{{ $slot->isEmpty() ? __('Logout') : $slot }}
	</button>
</form>
