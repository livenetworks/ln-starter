@props([
	'id' => '',
	'title' => '',
	'submitText' => 'Submit',
	'action' => null,
	'method' => 'POST',
])

<div class="ln-modal" id="{{ $id }}">
	<form
		@if($action)
			action="{{ $action }}"
			method="{{ $method === 'POST' ? 'POST' : 'GET' }}"
		@endif
		data-ln-ajax>

		<header>
			<h3>{{ $title }}</h3>
			<button type="button" class="ln-icon-close" data-ln-modal-close aria-label="{{ __('Close') }}"></button>
		</header>

		<main>
			{{ $slot }}
		</main>

		<footer>
			<button type="button" data-ln-modal-close>{{ __('Cancel') }}</button>
			<button type="submit">{{ $submitText }}</button>
		</footer>
	</form>
</div>
