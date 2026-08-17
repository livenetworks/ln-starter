@props([
	'id' => '',
	'title' => '',
	'submitText' => 'Submit',
	'action' => null,
	'method' => 'POST',
])

@php($formMethod = strtoupper($method))

<div class="ln-modal" id="{{ $id }}">
	<form
		@if($action)
			action="{{ $action }}"
			method="{{ $formMethod === 'GET' ? 'GET' : 'POST' }}"
		@endif
		data-ln-ajax>
		@if($formMethod !== 'GET')
			@csrf
		@endif
		@if(!in_array($formMethod, ['GET', 'POST'], true))
			@method($formMethod)
		@endif

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
